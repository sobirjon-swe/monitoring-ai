<?php

namespace App\Domain\Access;

interface AccessGateway
{
    /** @param array{tenant_id:string, project_id?:string, department_id?:string} $scope */
    public function authorize(TrustedActor $actor, string $purpose, array $scope): void;
}
