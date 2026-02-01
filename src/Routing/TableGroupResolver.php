<?php

declare(strict_types=1);

namespace Skylence\Shardwise\Routing;

/**
 * Resolves table groups to ensure related tables are routed together.
 */
final class TableGroupResolver
{
    /**
     * Map of table to group name.
     *
     * @var array<string, string>
     */
    private array $tableToGroup = [];

    /**
     * Map of group name to tables.
     *
     * @var array<string, array<int, string>>
     */
    private array $groups = [];

    /**
     * @param  array<string, array<int, string>>  $groups
     */
    public function __construct(array $groups = [])
    {
        $this->setGroups($groups);
    }

    /**
     * Create from configuration.
     */
    public static function fromConfig(): self
    {
        /** @var array<string, array<int, string>> $groups */
        $groups = config('shardwise.table_groups', []);

        return new self($groups);
    }

    /**
     * Set the table groups configuration.
     *
     * @param  array<string, array<int, string>>  $groups
     */
    public function setGroups(array $groups): void
    {
        $this->groups = $groups;
        $this->tableToGroup = [];

        foreach ($groups as $groupName => $tables) {
            foreach ($tables as $table) {
                $this->tableToGroup[$table] = $groupName;
            }
        }
    }

    /**
     * Get the group name for a table.
     */
    public function getGroupForTable(string $table): ?string
    {
        return $this->tableToGroup[$table] ?? null;
    }

    /**
     * Get all tables in a group.
     *
     * @return array<int, string>
     */
    public function getTablesInGroup(string $groupName): array
    {
        return $this->groups[$groupName] ?? [];
    }

    /**
     * Check if a table belongs to a group.
     */
    public function tableHasGroup(string $table): bool
    {
        return isset($this->tableToGroup[$table]);
    }

    /**
     * Get all group names.
     *
     * @return array<int, string>
     */
    public function getGroupNames(): array
    {
        return array_keys($this->groups);
    }

    /**
     * Get all tables that have been assigned to groups.
     *
     * @return array<int, string>
     */
    public function getGroupedTables(): array
    {
        return array_keys($this->tableToGroup);
    }

    /**
     * Check if two tables are in the same group.
     */
    public function areTablesInSameGroup(string $table1, string $table2): bool
    {
        $group1 = $this->getGroupForTable($table1);
        $group2 = $this->getGroupForTable($table2);

        if ($group1 === null || $group2 === null) {
            return false;
        }

        return $group1 === $group2;
    }

    /**
     * Add a table to a group.
     */
    public function addTableToGroup(string $table, string $groupName): void
    {
        if (! isset($this->groups[$groupName])) {
            $this->groups[$groupName] = [];
        }

        if (! in_array($table, $this->groups[$groupName], true)) {
            $this->groups[$groupName][] = $table;
        }

        $this->tableToGroup[$table] = $groupName;
    }

    /**
     * Remove a table from its group.
     */
    public function removeTableFromGroup(string $table): void
    {
        $groupName = $this->tableToGroup[$table] ?? null;

        if ($groupName !== null) {
            $this->groups[$groupName] = array_values(
                array_filter(
                    $this->groups[$groupName],
                    fn (string $t): bool => $t !== $table
                )
            );
        }

        unset($this->tableToGroup[$table]);
    }
}
