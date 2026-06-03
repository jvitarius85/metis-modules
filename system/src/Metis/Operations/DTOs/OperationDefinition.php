<?php
declare(strict_types=1);

namespace Metis\Operations\DTOs;

final class OperationDefinition {
    public function __construct(
        private readonly string $operationKey,
        private readonly string $commandKey,
        private readonly string $toolKey,
        private readonly string $title,
        private readonly string $description,
        private readonly string $module,
        private readonly string $domain,
        private readonly string $topLevelIntent,
        private readonly string $requiredPermission,
        private readonly array $requiredPermissions,
        private readonly bool $requiresApproval,
        private readonly bool $readOnly,
        private readonly bool $workerSupported,
        private readonly string $riskLevel,
        private readonly string $enclaveAction,
        private readonly array $inputSchema,
        private readonly array $outputSchema,
        private readonly array $dispatch,
        private readonly array $handlerMetadata = []
    ) {}

    public function toArray(): array {
        return [
            'operation_key' => $this->operationKey,
            'command_key' => $this->commandKey,
            'tool_key' => $this->toolKey,
            'title' => $this->title,
            'description' => $this->description,
            'module' => $this->module,
            'domain' => $this->domain,
            'top_level_intent' => $this->topLevelIntent,
            'required_permission' => $this->requiredPermission,
            'required_permissions' => $this->requiredPermissions,
            'requires_approval' => $this->requiresApproval,
            'read_only' => $this->readOnly,
            'worker_supported' => $this->workerSupported,
            'risk_level' => $this->riskLevel,
            'enclave_action' => $this->enclaveAction,
            'input_schema' => $this->inputSchema,
            'output_schema' => $this->outputSchema,
            'dispatch' => $this->dispatch,
            'handler_metadata' => $this->handlerMetadata,
        ];
    }
}
