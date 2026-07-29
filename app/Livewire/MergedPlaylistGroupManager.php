<?php

namespace App\Livewire;

use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class MergedPlaylistGroupManager extends Component
{
    private const string SettingsTable = 'merged_playlist_group_settings';

    public MergedPlaylist $record;

    public string $search = '';

    public int $perPage = 50;

    public int $page = 1;

    public string $filterStatus = 'all';

    public int $filterPlaylist = 0;

    public bool $customOrderEnabled = false;

    #[Locked]
    public array $rules = [];

    public string $ruleAction = 'include';

    public string $ruleMatchType = 'starts_with';

    public string $rulePattern = '';

    public bool $ruleCaseSensitive = false;

    public int $rulePlaylistId = 0;

    /** @var array<int|string, array{enabled: bool, is_new: bool, group_name: string, playlist_id: int}> */
    #[Locked]
    public array $groupState = [];

    /** @var array<int, int> */
    #[Locked]
    public array $groupOrder = [];

    /** @var array<int, string> */
    #[Locked]
    public array $playlists = [];

    public function mount(MergedPlaylist $record): void
    {
        Gate::authorize('update', $record);

        $this->record = $record;
        $this->customOrderEnabled = $record->hasCustomGroupOrder();
        $this->loadFromRecord();
    }

    public function save(): void
    {
        Gate::authorize('update', $this->record);

        $this->validate([
            'customOrderEnabled' => ['required', 'boolean'],
        ]);

        $playlistModels = $this->playlistModels();
        $canonical = $this->buildCanonicalItems($playlistModels);
        $orderedGroupIds = $this->sanitizedGroupOrder($canonical);
        $now = now();

        $rows = [];

        foreach ($orderedGroupIds as $sort => $groupId) {
            $rows[] = [
                'merged_playlist_id' => $this->record->getKey(),
                'group_id' => $groupId,
                'sort' => $sort + 1,
                'enabled' => (bool) ($this->groupState[$groupId]['enabled'] ?? true),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            MergedPlaylist::query()
                ->whereKey($this->record->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            DB::table(self::SettingsTable)
                ->where('merged_playlist_id', $this->record->getKey())
                ->delete();

            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table(self::SettingsTable)->insert($chunk);
            }

            $this->record->forceFill([
                'group_order_custom' => $this->customOrderEnabled,
            ])->save();
        }, attempts: 3);

        $this->loadFromRecord();
        $this->dispatch('group-order-saved');

        Notification::make()
            ->title(__('Group order saved'))
            ->body(__('Group order and enabled state updated for this merged playlist.'))
            ->success()
            ->send();
    }

    public function toggleGroup(int $groupId): void
    {
        if (isset($this->groupState[$groupId])) {
            $this->groupState[$groupId]['enabled'] = ! $this->groupState[$groupId]['enabled'];
        }
    }

    public function moveGroup(int $groupId, string $direction): void
    {
        if (! in_array($direction, ['up', 'down'], strict: true)) {
            return;
        }

        $currentIndex = array_search($groupId, $this->groupOrder, strict: true);

        if ($currentIndex === false) {
            return;
        }

        $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;

        if (! isset($this->groupOrder[$targetIndex])) {
            return;
        }

        [$this->groupOrder[$currentIndex], $this->groupOrder[$targetIndex]] = [
            $this->groupOrder[$targetIndex],
            $this->groupOrder[$currentIndex],
        ];
    }

    public function getGroupPosition(int $groupId): int
    {
        $index = array_search($groupId, $this->groupOrder, strict: true);

        return $index === false ? 0 : $index + 1;
    }

    public function enableAll(): void
    {
        foreach ($this->groupState as &$state) {
            $state['enabled'] = true;
        }
        unset($state);
    }

    public function disableAll(): void
    {
        foreach ($this->groupState as &$state) {
            $state['enabled'] = false;
        }
        unset($state);
    }

    public function enableFiltered(): void
    {
        $this->setEnabledForGroups($this->getFilteredGroupIds(), true);
    }

    public function disableFiltered(): void
    {
        $this->setEnabledForGroups($this->getFilteredGroupIds(), false);
    }

    public function addRule(): void
    {
        $validated = $this->validate([
            'ruleAction' => ['required', Rule::in(['include', 'exclude'])],
            'ruleMatchType' => ['required', Rule::in(['starts_with', 'ends_with', 'contains', 'exact', 'wildcard', 'regex'])],
            'rulePattern' => ['required', 'string', 'max:255'],
            'ruleCaseSensitive' => ['required', 'boolean'],
            'rulePlaylistId' => ['required', 'integer', Rule::in([0, ...array_keys($this->playlists)])],
        ]);

        if ($validated['ruleMatchType'] === 'regex' && ! $this->isValidRegex($validated['rulePattern'])) {
            $this->addError('rulePattern', __('Invalid regular expression.'));

            return;
        }

        if (count($this->rules) >= 100) {
            $this->addError('rulePattern', __('You may add up to :count rules.', ['count' => 100]));

            return;
        }

        $this->rules[] = [
            'action' => $validated['ruleAction'],
            'match_type' => $validated['ruleMatchType'],
            'pattern' => $validated['rulePattern'],
            'case_sensitive' => $validated['ruleCaseSensitive'],
            'playlist_id' => (int) $validated['rulePlaylistId'],
        ];

        $this->reset('rulePattern');
        $this->resetValidation('rulePattern');
    }

    public function removeRule(int $index): void
    {
        if (isset($this->rules[$index])) {
            array_splice($this->rules, $index, 1);
        }
    }

    public function applyRules(): void
    {
        if ($this->rules === []) {
            return;
        }

        $enabledCount = 0;
        $disabledCount = 0;

        foreach ($this->groupState as &$state) {
            $before = $state['enabled'];

            foreach ($this->rules as $rule) {
                $rulePlaylistId = (int) $rule['playlist_id'];

                if ($rulePlaylistId > 0 && $rulePlaylistId !== $state['playlist_id']) {
                    continue;
                }

                if ($this->matchesRule($state['group_name'], $rule)) {
                    $state['enabled'] = $rule['action'] === 'include';
                }
            }

            if ($state['enabled'] !== $before) {
                $state['enabled'] ? $enabledCount++ : $disabledCount++;
            }
        }
        unset($state);

        $parts = [];

        if ($enabledCount > 0) {
            $parts[] = __(':count enabled', ['count' => $enabledCount]);
        }

        if ($disabledCount > 0) {
            $parts[] = __(':count disabled', ['count' => $disabledCount]);
        }

        Notification::make()
            ->title(__('Rules applied'))
            ->body($parts === [] ? __('No groups were changed.') : implode(', ', $parts).' — '.__('click Save to persist.'))
            ->success()
            ->send();
    }

    public function nextPage(): void
    {
        if ($this->page < $this->getTotalPagesProperty()) {
            $this->page++;
        }
    }

    public function previousPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
        }
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedFilterStatus(): void
    {
        $this->page = 1;
    }

    public function updatedFilterPlaylist(): void
    {
        $this->page = 1;
    }

    public function updatedPerPage(int|string $value): void
    {
        $perPage = (int) $value;
        $this->perPage = in_array($perPage, [25, 50, 100], strict: true) ? $perPage : 50;
        $this->page = 1;
    }

    public function getTotalFilteredProperty(): int
    {
        return count($this->getFilteredGroupIds());
    }

    public function getTotalPagesProperty(): int
    {
        return max(1, (int) ceil($this->getTotalFilteredProperty() / $this->perPage));
    }

    /** @return array<int, int> */
    public function getPaginatedGroupIdsProperty(): array
    {
        return array_slice($this->getFilteredGroupIds(), ($this->page - 1) * $this->perPage, $this->perPage);
    }

    /** @return array{total: int, enabled: int, disabled: int, new: int} */
    public function getStatsProperty(): array
    {
        $enabled = 0;
        $new = 0;

        foreach ($this->groupState as $state) {
            $enabled += $state['enabled'] ? 1 : 0;
            $new += $state['is_new'] ? 1 : 0;
        }

        return [
            'total' => count($this->groupState),
            'enabled' => $enabled,
            'disabled' => count($this->groupState) - $enabled,
            'new' => $new,
        ];
    }

    public function render(): View
    {
        return view('livewire.merged-playlist-group-manager', [
            'paginatedGroupIds' => $this->getPaginatedGroupIdsProperty(),
            'totalFiltered' => $this->getTotalFilteredProperty(),
            'totalPages' => $this->getTotalPagesProperty(),
            'stats' => $this->getStatsProperty(),
        ]);
    }

    private function loadFromRecord(): void
    {
        $playlistModels = $this->playlistModels();
        $this->playlists = $playlistModels->pluck('name', 'id')->all();
        $canonical = $this->buildCanonicalItems($playlistModels);
        $savedSettings = DB::table(self::SettingsTable)
            ->where('merged_playlist_id', $this->record->getKey())
            ->orderBy('sort')
            ->get(['group_id', 'enabled']);

        $this->groupState = [];
        $this->groupOrder = [];
        $seen = [];

        foreach ($savedSettings as $setting) {
            $groupId = (int) $setting->group_id;

            if (! isset($canonical[$groupId])) {
                continue;
            }

            $this->groupOrder[] = $groupId;
            $this->groupState[$groupId] = [
                ...$canonical[$groupId],
                'enabled' => (bool) $setting->enabled,
                'is_new' => false,
            ];
            $seen[$groupId] = true;
        }

        $hasSavedSettings = $savedSettings->isNotEmpty();

        foreach ($canonical as $groupId => $metadata) {
            if (isset($seen[$groupId])) {
                continue;
            }

            $this->groupOrder[] = $groupId;
            $this->groupState[$groupId] = [
                ...$metadata,
                'enabled' => true,
                'is_new' => $hasSavedSettings,
            ];
        }
    }

    /** @return Collection<int, Playlist> */
    private function playlistModels(): Collection
    {
        return $this->record->playlists()->get(['playlists.id', 'playlists.name']);
    }

    /**
     * @param  Collection<int, Playlist>  $playlistModels
     * @return array<int, array{group_name: string, playlist_id: int}>
     */
    private function buildCanonicalItems(Collection $playlistModels): array
    {
        $groupsByPlaylist = Group::query()
            ->whereIn('playlist_id', $playlistModels->modelKeys())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'playlist_id'])
            ->groupBy('playlist_id');
        $items = [];

        foreach ($playlistModels as $playlist) {
            foreach ($groupsByPlaylist->get($playlist->getKey(), collect()) as $group) {
                $items[$group->getKey()] = [
                    'group_name' => $group->name,
                    'playlist_id' => $playlist->getKey(),
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<int, array{group_name: string, playlist_id: int}>  $canonical
     * @return array<int, int>
     */
    private function sanitizedGroupOrder(array $canonical): array
    {
        $ordered = [];
        $seen = [];

        foreach ($this->groupOrder as $groupId) {
            $groupId = (int) $groupId;

            if (! isset($canonical[$groupId]) || isset($seen[$groupId])) {
                continue;
            }

            $ordered[] = $groupId;
            $seen[$groupId] = true;
        }

        foreach (array_keys($canonical) as $groupId) {
            if (! isset($seen[$groupId])) {
                $ordered[] = $groupId;
            }
        }

        return $ordered;
    }

    /** @return array<int, int> */
    private function getFilteredGroupIds(): array
    {
        $search = mb_strtolower(trim($this->search));

        return array_values(array_filter($this->groupOrder, function (int $groupId) use ($search): bool {
            $state = $this->groupState[$groupId] ?? null;

            if ($state === null) {
                return false;
            }

            if ($search !== '' && ! str_contains(mb_strtolower($state['group_name']), $search)) {
                return false;
            }

            if ($this->filterStatus === 'enabled' && ! $state['enabled']) {
                return false;
            }

            if ($this->filterStatus === 'disabled' && $state['enabled']) {
                return false;
            }

            return $this->filterPlaylist === 0 || $state['playlist_id'] === $this->filterPlaylist;
        }));
    }

    /** @param array<int, int> $groupIds */
    private function setEnabledForGroups(array $groupIds, bool $enabled): void
    {
        foreach ($groupIds as $groupId) {
            if (isset($this->groupState[$groupId])) {
                $this->groupState[$groupId]['enabled'] = $enabled;
            }
        }
    }

    /** @param array{match_type: string, pattern: string, case_sensitive: bool} $rule */
    private function matchesRule(string $label, array $rule): bool
    {
        $pattern = $rule['pattern'];
        $caseSensitive = $rule['case_sensitive'];
        $subject = $caseSensitive ? $label : mb_strtolower($label);
        $needle = $caseSensitive ? $pattern : mb_strtolower($pattern);

        return match ($rule['match_type']) {
            'starts_with' => str_starts_with($subject, $needle),
            'ends_with' => str_ends_with($subject, $needle),
            'contains' => str_contains($subject, $needle),
            'exact' => $subject === $needle,
            'wildcard' => fnmatch($needle, $subject),
            'regex' => preg_match($this->regexPattern($pattern, $caseSensitive), $label) === 1,
            default => false,
        };
    }

    private function isValidRegex(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($this->regexPattern($pattern, true), '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private function regexPattern(string $pattern, bool $caseSensitive): string
    {
        if (str_starts_with($pattern, '/') && strrpos($pattern, '/') > 0) {
            return $pattern;
        }

        return '/'.str_replace('/', '\/', $pattern).'/'.($caseSensitive ? '' : 'i');
    }
}
