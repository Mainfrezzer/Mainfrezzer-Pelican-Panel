<?php

namespace App\Http\Requests\Api\Application\Servers;

use App\Services\Acl\Api\AdminAcl;
use App\Http\Requests\Api\Application\ApplicationApiRequest;
use App\Models\Server;

class GetExternalServerRequest extends ApplicationApiRequest
{
    protected ?string $resource = Server::RESOURCE_NAME;

    protected int $permission = AdminAcl::READ;
}