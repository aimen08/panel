<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Permission;
use Illuminate\Auth\Access\AuthorizationException;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Services\Backups\DownloadLinkService;
use Pterodactyl\Services\Backups\InitiateBackupService;
use Pterodactyl\Repositories\Wings\DaemonBackupRepository;
use Pterodactyl\Transformers\Api\Client\BackupTransformer;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Pterodactyl\Http\Requests\Api\Client\Servers\Backups\StoreBackupRequest;

class BackupController extends ClientApiController
{
    private InitiateBackupService $initiateBackupService;
    private DeleteBackupService $deleteBackupService;
    private DownloadLinkService $downloadLinkService;
    private DaemonBackupRepository $repository;

    /**
     * BackupController constructor.
     */
    public function __construct(
        DaemonBackupRepository $repository,
        DeleteBackupService $deleteBackupService,
        InitiateBackupService $initiateBackupService,
        DownloadLinkService $downloadLinkService
    ) {
        parent::__construct();

        $this->repository = $repository;
        $this->initiateBackupService = $initiateBackupService;
        $this->deleteBackupService = $deleteBackupService;
        $this->downloadLinkService = $downloadLinkService;
    }

    /**
     * Returns all of the backups for a given server instance in a paginated
     * result set.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function index(Request $request, Server $server): array
    {
        if (!$request->user()->can(Permission::ACTION_BACKUP_READ, $server)) {
            throw new AuthorizationException();
        }

        $limit = min($request->query('per_page') ?? 20, 50);

        return $this->fractal->collection($server->backups()->paginate($limit))
            ->transformWith($this->getTransformer(BackupTransformer::class))
            ->toArray();
    }

    /**
     * Starts the backup process for a server.
     *
     * @throws \Throwable
     */
    public function store(StoreBackupRequest $request, Server $server): array
    {
        /** @var \Pterodactyl\Models\Backup $backup */
        $backup = $server->audit(AuditLog::SERVER__BACKUP_STARTED, function (AuditLog $model, Server $server) use ($request) {
            $backup = $this->initiateBackupService
                ->setIgnoredFiles(
                    explode(PHP_EOL, $request->input('ignored') ?? '')
                )
                ->handle($server, $request->input('name'));

            $model->metadata = ['backup_uuid' => $backup->uuid];

            return $backup;
        });

        return $this->fractal->item($backup)
            ->transformWith($this->getTransformer(BackupTransformer::class))
            ->toArray();
    }

    /**
     * Returns information about a single backup.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function view(Request $request, Server $server, Backup $backup): array
    {
        if (!$request->user()->can(Permission::ACTION_BACKUP_READ, $server)) {
            throw new AuthorizationException();
        }

        return $this->fractal->item($backup)
            ->transformWith($this->getTransformer(BackupTransformer::class))
            ->toArray();
    }

    /**
     * Deletes a backup from the panel as well as the remote source where it is currently
     * being stored.
     *
     * @throws \Throwable
     */
    public function delete(Request $request, Server $server, Backup $backup): Response
    {
        if (!$request->user()->can(Permission::ACTION_BACKUP_DELETE, $server)) {
            throw new AuthorizationException();
        }

        $server->audit(AuditLog::SERVER__BACKUP_DELETED, function (AuditLog $audit) use ($backup) {
            $audit->metadata = ['backup_uuid' => $backup->uuid];

            $this->deleteBackupService->handle($backup);
        });

        return $this->returnNoContent();
    }

    /**
     * Download the backup for a given server instance. For daemon local files, the file
     * will be streamed back through the Panel. For AWS S3 files, a signed URL will be generated
     * which the user is redirected to.
     *
     * @throws \Throwable
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function download(Request $request, Server $server, Backup $backup): JsonResponse
    {
        if (!$request->user()->can(Permission::ACTION_BACKUP_DOWNLOAD, $server)) {
            throw new AuthorizationException();
        }

        if ($backup->disk !== Backup::ADAPTER_AWS_S3 && $backup->disk !== Backup::ADAPTER_WINGS) {
            throw new BadRequestHttpException('The backup requested references an unknown disk driver type and cannot be downloaded.');
        }

        $url = $this->downloadLinkService->handle($backup, $request->user());
        $server->audit(AuditLog::SERVER__BACKUP_DOWNLOADED, function (AuditLog $audit) use ($backup) {
            $audit->metadata = ['backup_uuid' => $backup->uuid];
        });

        return new JsonResponse([
            'object' => 'signed_url',
            'attributes' => ['url' => $url],
        ]);
    }

    /**
     * Handles restoring a backup by making a request to the Wings instance telling it
     * to begin the process of finding (or downloading) the backup and unpacking it
     * over the server files.
     *
     * If the "truncate" flag is passed through in this request then all of the
     * files that currently exist on the server will be deleted before restoring.
     * Otherwise the archive will simply be unpacked over the existing files.
     *
     * @throws \Throwable
     */
    public function restore(Request $request, Server $server, Backup $backup): Response
    {
        if (!$request->user()->can(Permission::ACTION_BACKUP_RESTORE, $server)) {
            throw new AuthorizationException();
        }

        // Cannot restore a backup unless a server is fully installed and not currently
        // processing a different backup restoration request.
        if (!is_null($server->status)) {
            throw new BadRequestHttpException('This server is not currently in a state that allows for a backup to be restored.');
        }

        if (!$backup->is_successful && !$backup->completed_at) {
            throw new BadRequestHttpException('This backup cannot be restored at this time: not completed or failed.');
        }

        $server->audit(AuditLog::SERVER__BACKUP_RESTORE_STARTED, function (AuditLog $audit, Server $server) use ($backup, $request) {
            $audit->metadata = ['backup_uuid' => $backup->uuid];

            // If the backup is for an S3 file we need to generate a unique Download link for
            // it that will allow Wings to actually access the file.
            if ($backup->disk === Backup::ADAPTER_AWS_S3) {
                $url = $this->downloadLinkService->handle($backup, $request->user());
            }

            // Update the status right away for the server so that we know not to allow certain
            // actions against it via the Panel API.
            $server->update(['status' => Server::STATUS_RESTORING_BACKUP]);

            $this->repository->setServer($server)->restore($backup, $url ?? null, $request->input('truncate') === 'true');
        });

        return $this->returnNoContent();
    }
}
