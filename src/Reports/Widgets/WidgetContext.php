<?php

namespace RivetMSP\Reports\Widgets;

/**
 * Who a widget is being computed for: the user, the clients they may see (null = every client) and a
 * permission probe. Built from the session in the page, built by hand in tests.
 */
final class WidgetContext
{
    /** @var callable(string, int=): bool */
    private $can;

    public function __construct(
        public int $userId,
        ?array $scope,
        callable $can,
        public bool $csatEnabled = true,
        public bool $excludeProjects = false,
        public int $csatLowThreshold = 2,
        ?int $year = null,
        public bool $avgResolutionEnabled = true
    ) {
        $this->can = $can;
        $this->scope = $scope;
        $this->year = $year ?? (int) date('Y');
    }

    public ?array $scope;
    public int $year;

    public function can(string $module, int $level = 1): bool
    {
        return (bool) ($this->can)($module, $level);
    }

    /** " AND col IN (...)" for the clients this user may see, or '' for a user who sees every client. */
    public function scope(string $column): string
    {
        if ($this->scope === null) {
            return '';
        }
        $ids = array_values(array_filter(array_map('intval', $this->scope), static fn (int $i): bool => $i > 0));
        return $ids ? " AND $column IN (" . implode(',', $ids) . ')' : " AND $column IN (0)";
    }
}
