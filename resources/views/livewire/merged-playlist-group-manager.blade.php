<div class="space-y-4">

    <div class="flex items-start justify-between gap-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
        <div>
            <div class="font-medium text-gray-950 dark:text-white">{{ __('Custom group order') }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('When disabled, groups follow the order of the source playlists (see the Playlists tab), then each playlist\'s own group order. When enabled, groups are delivered in the custom order set below.') }}
            </div>
        </div>
        <x-filament::input.wrapper>
            <x-filament::input
                type="checkbox"
                wire:model="customOrderEnabled"
                aria-label="{{ __('Custom group order') }}"
            />
        </x-filament::input.wrapper>
    </div>

    {{-- ── Stats bar ──────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap gap-3 text-sm">
        <x-filament::badge color="gray">
            {{ __('Total') }}: {{ $stats['total'] }}
        </x-filament::badge>
        <x-filament::badge color="success">
            {{ __('Enabled') }}: {{ $stats['enabled'] }}
        </x-filament::badge>
        <x-filament::badge color="danger">
            {{ __('Disabled') }}: {{ $stats['disabled'] }}
        </x-filament::badge>
        @if($stats['new'] > 0)
        <x-filament::badge color="warning">
            {{ __('New') }}: {{ $stats['new'] }}
        </x-filament::badge>
        @endif
    </div>

    {{-- ── Rule engine ─────────────────────────────────────────────────── --}}
    <x-filament::section
        :collapsible="true"
        collapsed
        compact
        :heading="count($rules) > 0 ? __('Filter Rules (:count)', ['count' => count($rules)]) : __('Filter Rules')"
    >
        <div class="space-y-3">
            {{-- Rule builder row --}}
            <div class="flex flex-wrap gap-2 items-end">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('Action') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="ruleAction">
                            <option value="include">{{ __('Include') }}</option>
                            <option value="exclude">{{ __('Exclude') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('Playlist') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="rulePlaylistId">
                            <option value="0">{{ __('All playlists') }}</option>
                            @foreach($playlists as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('Match type') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model="ruleMatchType">
                            <option value="starts_with">{{ __('Starts with') }}</option>
                            <option value="ends_with">{{ __('Ends with') }}</option>
                            <option value="contains">{{ __('Contains') }}</option>
                            <option value="exact">{{ __('Exact') }}</option>
                            <option value="wildcard">{{ __('Wildcard (*, ?)') }}</option>
                            <option value="regex">{{ __('Regex') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">{{ __('Pattern') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input
                            type="text"
                            wire:model="rulePattern"
                            wire:keydown.enter="addRule"
                            :placeholder="__('e.g. US - or ^(US|CA)')"
                        />
                    </x-filament::input.wrapper>
                    @error('rulePattern')
                        <p class="mt-1 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex items-center gap-1 pb-2.5">
                    <x-filament::input type="checkbox" wire:model="ruleCaseSensitive" id="rule-case" />
                    <label for="rule-case" class="text-xs text-gray-500 dark:text-gray-400">{{ __('Case sensitive') }}</label>
                </div>
                <x-filament::button wire:click="addRule" size="sm" icon="heroicon-m-plus">
                    {{ __('Add') }}
                </x-filament::button>
            </div>

            {{-- Saved rules list --}}
            @if(count($rules) > 0)
            <div class="space-y-1">
                @foreach($rules as $i => $rule)
                <div class="flex items-center gap-2 text-sm bg-gray-50 dark:bg-gray-800 rounded px-3 py-2">
                    <x-filament::badge :color="$rule['action'] === 'include' ? 'success' : 'danger'">
                        {{ $rule['action'] === 'include' ? __('Include') : __('Exclude') }}
                    </x-filament::badge>
                    @if(($rule['playlist_id'] ?? 0) > 0)
                        <x-filament::badge color="info">
                            {{ $playlists[$rule['playlist_id']] ?? __('Playlist :id', ['id' => $rule['playlist_id']]) }}
                        </x-filament::badge>
                    @endif
                    <span class="text-gray-400">&middot;</span>
                    <span class="text-gray-600 dark:text-gray-400">{{ str_replace('_', ' ', $rule['match_type']) }}</span>
                    <span class="text-gray-400">&middot;</span>
                    <code class="font-mono text-xs bg-gray-100 dark:bg-gray-700 px-1 rounded">{{ $rule['pattern'] }}</code>
                    @if($rule['case_sensitive'])
                        <span class="text-xs text-gray-400">({{ __('case sensitive') }})</span>
                    @endif
                    <x-filament::icon-button
                        icon="heroicon-m-x-mark"
                        :label="__('Remove rule')"
                        color="danger"
                        class="ml-auto"
                        wire:click="removeRule({{ $i }})"
                    />
                </div>
                @endforeach
            </div>

            <div class="flex items-center gap-3 pt-1">
                <x-filament::button wire:click="applyRules" size="sm" color="primary" icon="heroicon-m-funnel">
                    {{ __('Apply Rules to All Groups') }}
                </x-filament::button>
                <span class="text-xs text-gray-400">{{ __('Rules run top-to-bottom. Later rules override earlier ones.') }}</span>
            </div>
            @endif
        </div>
    </x-filament::section>

    {{-- ── Search + filter bar ─────────────────────────────────────────── --}}
    <div class="flex flex-wrap gap-2 items-center">
        <div class="flex-1 min-w-[200px]">
            <x-filament::input.wrapper>
                <x-filament::input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    :placeholder="__('Search groups...')"
                />
            </x-filament::input.wrapper>
        </div>
        <x-filament::input.wrapper class="w-auto">
            <x-filament::input.select wire:model.live="filterPlaylist">
                <option value="0">{{ __('All playlists') }}</option>
                @foreach($playlists as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <x-filament::input.wrapper class="w-auto">
            <x-filament::input.select wire:model.live="filterStatus">
                <option value="all">{{ __('All groups') }}</option>
                <option value="enabled">{{ __('Enabled only') }}</option>
                <option value="disabled">{{ __('Disabled only') }}</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <x-filament::input.wrapper class="w-auto">
            <x-filament::input.select wire:model.live="perPage">
                <option value="25">{{ __(':count per page', ['count' => 25]) }}</option>
                <option value="50">{{ __(':count per page', ['count' => 50]) }}</option>
                <option value="100">{{ __(':count per page', ['count' => 100]) }}</option>
                <option value="250">{{ __(':count per page', ['count' => 250]) }}</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    {{-- ── Bulk action buttons ──────────────────────────────────────────── --}}
    <div class="flex flex-wrap gap-2">
        <x-filament::button wire:click="enableAll" size="xs" color="success" icon="heroicon-m-check-circle">
            {{ __('Enable All') }}
        </x-filament::button>
        <x-filament::button wire:click="disableAll" size="xs" color="danger" icon="heroicon-m-x-circle">
            {{ __('Disable All') }}
        </x-filament::button>
        @if(trim($search) !== '' || $filterStatus !== 'all' || (int)$filterPlaylist > 0)
        <x-filament::button wire:click="enableFiltered" size="xs" color="success" icon="heroicon-m-check">
            {{ __('Enable Filtered (:count)', ['count' => $totalFiltered]) }}
        </x-filament::button>
        <x-filament::button wire:click="disableFiltered" size="xs" color="danger" icon="heroicon-m-x-mark">
            {{ __('Disable Filtered (:count)', ['count' => $totalFiltered]) }}
        </x-filament::button>
        @endif
    </div>

    {{-- ── Group table ──────────────────────────────────────────────────── --}}
    <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-400 w-12">#</th>
                    <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-400">{{ __('Group') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-gray-600 dark:text-gray-400">{{ __('Playlist') }}</th>
                    <th class="px-4 py-2 text-center font-medium text-gray-600 dark:text-gray-400 w-24">{{ __('Active') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse($paginatedGroupIds as $groupId)
                    @php($state = $groupState[$groupId] ?? ['enabled' => true, 'is_new' => false, 'group_name' => (string) $groupId, 'playlist_id' => 0])
                    @php($position = $this->getGroupPosition($groupId))
                    <tr
                        wire:key="merged-playlist-group-{{ $groupId }}"
                        class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50 {{ ! $state['enabled'] ? 'opacity-50' : '' }}"
                    >
                        <td class="px-4 py-2 text-gray-400 text-xs">
                            <div class="flex items-center gap-1">
                                <span class="w-7">{{ $position }}</span>
                                <x-filament::icon-button
                                    icon="heroicon-m-chevron-up"
                                    :label="__('Move up')"
                                    size="xs"
                                    wire:click="moveGroup({{ $groupId }}, 'up')"
                                    :disabled="$position <= 1"
                                />
                                <x-filament::icon-button
                                    icon="heroicon-m-chevron-down"
                                    :label="__('Move down')"
                                    size="xs"
                                    wire:click="moveGroup({{ $groupId }}, 'down')"
                                    :disabled="$position >= count($groupOrder)"
                                />
                            </div>
                        </td>
                        <td class="px-4 py-2 text-gray-800 dark:text-gray-200">
                            {{ $state['group_name'] }}
                            @if($state['is_new'] ?? false)
                                <x-filament::badge color="warning" size="sm">{{ __('new') }}</x-filament::badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400 text-xs">
                            {{ $playlists[$state['playlist_id'] ?? 0] ?? '' }}
                        </td>
                        <td class="px-4 py-2 text-center">
                            <x-filament::icon-button
                                :icon="$state['enabled'] ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle'"
                                :color="$state['enabled'] ? 'success' : 'gray'"
                                :label="$state['enabled'] ? __('Enabled') : __('Disabled')"
                                wire:click="toggleGroup({{ $groupId }})"
                            />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-400">
                            {{ trim($search) !== '' ? __('No groups found matching ":search".', ['search' => $search]) : __('No groups found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ── Pagination ───────────────────────────────────────────────────── --}}
    @if($totalPages > 1)
    <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
        <span>
            {{ __('Showing :from–:to of :total', ['from' => ($page - 1) * $perPage + 1, 'to' => min($page * $perPage, $totalFiltered), 'total' => $totalFiltered]) }}
        </span>
        <div class="flex gap-2">
            <x-filament::button wire:click="previousPage" size="xs" color="gray" :disabled="$page <= 1" icon="heroicon-m-chevron-left">
                {{ __('Prev') }}
            </x-filament::button>
            <span class="px-3 py-1">{{ $page }} / {{ $totalPages }}</span>
            <x-filament::button wire:click="nextPage" size="xs" color="gray" :disabled="$page >= $totalPages" icon-position="after" icon="heroicon-m-chevron-right">
                {{ __('Next') }}
            </x-filament::button>
        </div>
    </div>
    @endif

    {{-- ── Save button ──────────────────────────────────────────────────── --}}
    <div class="flex justify-end pt-2">
        <x-filament::button wire:click="save" icon="heroicon-m-check" color="primary">
            {{ __('Save Group Order') }}
        </x-filament::button>
    </div>

</div>
