<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\GraphQL;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Nuwave\Lighthouse\Events\StartExecution;
use Skeylup\OwlogsAgent\Compat\ContextShim;

/**
 * Rewrites the URI of `/graphql` requests so the Owlogs UI groups by
 * operation (e.g. `POST /graphql — mutation createReport`) instead of the
 * single opaque `/graphql` endpoint, and stashes the operation breakdown
 * under `extra.graphql_operations` for the detail view.
 *
 * Wired in only when `nuwave/lighthouse` is installed — gated by
 * `class_exists()` in OwlogsAgentServiceProvider. StartExecution fires once
 * per operation, so batched requests accumulate every operation; the URI keeps
 * the FIRST one for a stable, low-cardinality grouping key.
 */
class OwlogsLighthouseListener
{
    /** Cap stored operations so a batched request can't bloat the log row. */
    private const MAX_OPERATIONS_STORED = 10;

    /** Cap root fields kept per operation. */
    private const MAX_ROOT_FIELDS = 5;

    public function handle(StartExecution $event): void
    {
        $type = $this->resolveOperationType($event);
        $rootFields = $this->resolveRootFields($event);

        // Introspection queries (__schema / __type) are IDE plumbing — skip.
        if (config('owlogs.graphql.ignore_introspection', true)
            && $rootFields !== []
            && str_starts_with($rootFields[0], '__')
        ) {
            return;
        }

        // Prefer the client operation name; fall back to the root field list.
        $name = $event->operationName
            ?? implode(', ', array_slice($rootFields, 0, self::MAX_ROOT_FIELDS));

        $label = trim($type.' '.$name);

        // Only the first operation of a (possibly batched) request sets the
        // URI, so the grouping key stays stable.
        if (! ContextShim::hasHidden('graphql_label')) {
            ContextShim::addHidden('graphql_label', $label);
            ContextShim::addHidden('route_action', $label);
            ContextShim::addHidden('uri', Request::method().' /graphql — '.$label);
        }

        $existing = ContextShim::getHidden('graphql_operations');
        if ((is_array($existing) ? count($existing) : 0) < self::MAX_OPERATIONS_STORED) {
            ContextShim::pushHidden('graphql_operations', [
                'type' => $type,
                'name' => $event->operationName,
                'root_fields' => array_slice($rootFields, 0, self::MAX_ROOT_FIELDS),
            ]);
        }

        // Optional standalone timeline row (toggle owlogs.auto_log.graphql_operation).
        if (config('owlogs.auto_log.graphql_operation', false)) {
            Log::channel('owlogs')?->debug('graphql.operation: '.$label, [
                'type' => $type,
                'operation_name' => $event->operationName,
                'root_fields' => array_slice($rootFields, 0, self::MAX_ROOT_FIELDS),
            ]);
        }
    }

    private function resolveOperationType(StartExecution $event): string
    {
        foreach ($event->query->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode) {
                return $definition->operation; // 'query' | 'mutation' | 'subscription'
            }
        }

        return 'operation';
    }

    /**
     * @return list<string>
     */
    private function resolveRootFields(StartExecution $event): array
    {
        $fields = [];

        foreach ($event->query->definitions as $definition) {
            if (! $definition instanceof OperationDefinitionNode) {
                continue;
            }

            foreach ($definition->selectionSet->selections as $selection) {
                if ($selection instanceof FieldNode) {
                    $fields[] = $selection->name->value;
                }
            }
        }

        return $fields;
    }
}
