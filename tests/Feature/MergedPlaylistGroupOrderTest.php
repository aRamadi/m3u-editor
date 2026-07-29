<?php

/**
 * Tests for merged playlist group ordering.
 *
 * Verifies that:
 * - By default, groups from the first-attached source playlist come before
 *   groups from later-attached source playlists, regardless of each group's
 *   raw sort_order value (which is only unique/meaningful within its own
 *   source playlist, not across the merge).
 * - A custom saved group order overrides the default when enabled.
 * - Reordering the source playlists (i.e. updating the merged_playlist_playlist
 *   pivot's `sort` column, as the "Playlists" relation manager's drag-to-reorder
 *   does) changes the default group order.
 * - Xtream API category listings (getMergedPlaylistGroupsQuery) follow the same
 *   ordering rules as the M3U/channel output (getChannelQuery).
 * - A group the user disables via the saved group order is excluded from that
 *   merged playlist's own output only — it must not touch the source playlist's
 *   Group/Channel `enabled` state, since that column is shared with the source
 *   playlist's own output and any other merged playlist that also includes it.
 */

use App\Http\Controllers\PlaylistGenerateController;
use App\Livewire\MergedPlaylistGroupManager;
use App\Models\Channel;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
});

function storeMergedPlaylistGroupSettings(MergedPlaylist $mergedPlaylist, array $settings): void
{
    $now = now();

    DB::table('merged_playlist_group_settings')->insert(
        collect($settings)
            ->map(fn (array $setting, int $index): array => [
                'merged_playlist_id' => $mergedPlaylist->id,
                'group_id' => $setting['group']->id,
                'sort' => $index + 1,
                'enabled' => $setting['enabled'] ?? true,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all()
    );
}

it('defaults merged playlist group order to source playlist attach order, then each playlist group order', function () {
    $playlistOne = Playlist::factory()->for($this->user)->create();
    $playlistTwo = Playlist::factory()->for($this->user)->create();

    // Deliberately give playlist two's groups a *lower* raw sort_order so a naive
    // "ORDER BY groups.sort_order" would incorrectly surface them before playlist one's.
    $groupOneA = Group::factory()->for($playlistOne)->for($this->user)->create(['sort_order' => 100]);
    $groupOneB = Group::factory()->for($playlistOne)->for($this->user)->create(['sort_order' => 200]);
    $groupTwoA = Group::factory()->for($playlistTwo)->for($this->user)->create(['sort_order' => 1]);
    $groupTwoB = Group::factory()->for($playlistTwo)->for($this->user)->create(['sort_order' => 2]);

    Channel::factory()->for($this->user)->for($playlistOne)->for($groupOneA)->create(['enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'One A']);
    Channel::factory()->for($this->user)->for($playlistOne)->for($groupOneB)->create(['enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'One B']);
    Channel::factory()->for($this->user)->for($playlistTwo)->for($groupTwoA)->create(['enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'Two A']);
    Channel::factory()->for($this->user)->for($playlistTwo)->for($groupTwoB)->create(['enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'Two B']);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($playlistOne->id);
    $merged->playlists()->attach($playlistTwo->id);

    $titles = PlaylistGenerateController::getChannelQuery($merged)->get()->pluck('title')->all();

    expect($titles)->toBe(['One A', 'One B', 'Two A', 'Two B']);
});

it('respects the attach order when a playlist attached later has a lower pivot id', function () {
    $playlistOne = Playlist::factory()->for($this->user)->create();
    $playlistTwo = Playlist::factory()->for($this->user)->create();

    $groupOne = Group::factory()->for($playlistOne)->for($this->user)->create(['sort_order' => 1]);
    $groupTwo = Group::factory()->for($playlistTwo)->for($this->user)->create(['sort_order' => 1]);

    Channel::factory()->for($this->user)->for($playlistOne)->for($groupOne)->create(['enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'One']);
    Channel::factory()->for($this->user)->for($playlistTwo)->for($groupTwo)->create(['enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'Two']);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    // Attach playlist two first: it should come first in the default order,
    // even though playlist one has the lower primary key.
    $merged->playlists()->attach($playlistTwo->id);
    $merged->playlists()->attach($playlistOne->id);

    $titles = PlaylistGenerateController::getChannelQuery($merged)->get()->pluck('title')->all();

    expect($titles)->toBe(['Two', 'One']);
});

it('uses the saved custom group order for a merged playlist when enabled', function () {
    $playlistOne = Playlist::factory()->for($this->user)->create();
    $playlistTwo = Playlist::factory()->for($this->user)->create();

    $groupOneA = Group::factory()->for($playlistOne)->for($this->user)->create(['sort_order' => 1, 'name_internal' => 'One A Group']);
    $groupTwoA = Group::factory()->for($playlistTwo)->for($this->user)->create(['sort_order' => 1, 'name_internal' => 'Two A Group']);

    Channel::factory()->for($this->user)->for($playlistOne)->for($groupOneA)->create([
        'enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'One A', 'group_internal' => 'One A Group',
    ]);
    Channel::factory()->for($this->user)->for($playlistTwo)->for($groupTwoA)->create([
        'enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'Two A', 'group_internal' => 'Two A Group',
    ]);

    $merged = MergedPlaylist::factory()->for($this->user)->create(['group_order_custom' => true]);
    $merged->playlists()->attach($playlistOne->id);
    $merged->playlists()->attach($playlistTwo->id);
    storeMergedPlaylistGroupSettings($merged, [
        ['group' => $groupTwoA],
        ['group' => $groupOneA],
    ]);

    $titles = PlaylistGenerateController::getChannelQuery($merged)->get()->pluck('title')->all();

    expect($titles)->toBe(['Two A', 'One A']);
});

it('falls back to the default order when custom group ordering is disabled', function () {
    $playlistOne = Playlist::factory()->for($this->user)->create();
    $playlistTwo = Playlist::factory()->for($this->user)->create();

    $groupOneA = Group::factory()->for($playlistOne)->for($this->user)->create(['sort_order' => 1, 'name_internal' => 'One A Group']);
    $groupTwoA = Group::factory()->for($playlistTwo)->for($this->user)->create(['sort_order' => 1, 'name_internal' => 'Two A Group']);

    Channel::factory()->for($this->user)->for($playlistOne)->for($groupOneA)->create([
        'enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'One A', 'group_internal' => 'One A Group',
    ]);
    Channel::factory()->for($this->user)->for($playlistTwo)->for($groupTwoA)->create([
        'enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'Two A', 'group_internal' => 'Two A Group',
    ]);

    $merged = MergedPlaylist::factory()->for($this->user)->create(['group_order_custom' => false]);
    $merged->playlists()->attach($playlistOne->id);
    $merged->playlists()->attach($playlistTwo->id);
    storeMergedPlaylistGroupSettings($merged, [
        ['group' => $groupTwoA],
        ['group' => $groupOneA],
    ]);

    $titles = PlaylistGenerateController::getChannelQuery($merged)->get()->pluck('title')->all();

    expect($titles)->toBe(['One A', 'Two A']);
});

it('persists a custom merged playlist group order via the Group Order tab\'s own save action', function () {
    // The "Group Order" tab embeds MergedPlaylistGroupManager as a self-contained
    // Livewire component with its own Save button — it is not a field on the parent
    // Filament edit form, so persistence only happens through the component's save().
    $this->actingAs($this->user);

    $playlistOne = Playlist::factory()->for($this->user)->create();
    $playlistTwo = Playlist::factory()->for($this->user)->create();
    $sports = Group::factory()->for($playlistOne)->for($this->user)->create(['sort_order' => 1, 'name' => 'Sports', 'name_internal' => 'Sports']);
    $news = Group::factory()->for($playlistTwo)->for($this->user)->create(['sort_order' => 1, 'name' => 'News', 'name_internal' => 'News']);

    $merged = MergedPlaylist::factory()->for($this->user)->create(['user_agent' => 'Mozilla/5.0']);
    $merged->playlists()->attach($playlistOne->id);
    $merged->playlists()->attach($playlistTwo->id);

    Livewire::test(MergedPlaylistGroupManager::class, ['record' => $merged])
        ->set('customOrderEnabled', true)
        ->call('moveGroup', $news->id, 'up')
        ->call('toggleGroup', $news->id)
        ->call('save');

    $merged->refresh();

    expect($merged->group_order_custom)->toBeTrue();
    expect(
        DB::table('merged_playlist_group_settings')
            ->where('merged_playlist_id', $merged->id)
            ->orderBy('sort')
            ->get(['group_id', 'enabled'])
            ->map(fn (object $setting): array => [
                'group_id' => (int) $setting->group_id,
                'enabled' => (bool) $setting->enabled,
            ])
            ->all()
    )->toBe([
        ['group_id' => $news->id, 'enabled' => false],
        ['group_id' => $sports->id, 'enabled' => true],
    ]);
});

it('reflects a reordered source playlist attach order (pivot sort) in the default group order', function () {
    $playlistOne = Playlist::factory()->for($this->user)->create();
    $playlistTwo = Playlist::factory()->for($this->user)->create();

    $groupOne = Group::factory()->for($playlistOne)->for($this->user)->create(['sort_order' => 1]);
    $groupTwo = Group::factory()->for($playlistTwo)->for($this->user)->create(['sort_order' => 1]);

    Channel::factory()->for($this->user)->for($playlistOne)->for($groupOne)->create(['enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'One']);
    Channel::factory()->for($this->user)->for($playlistTwo)->for($groupTwo)->create(['enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'Two']);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($playlistOne->id);
    $merged->playlists()->attach($playlistTwo->id);

    expect(PlaylistGenerateController::getChannelQuery($merged)->get()->pluck('title')->all())
        ->toBe(['One', 'Two']);

    // Simulate what the "Playlists" relation manager's drag-to-reorder does: renumber
    // the pivot `sort` column so playlist two now comes first.
    $merged->playlists()->updateExistingPivot($playlistTwo->id, ['sort' => 1]);
    $merged->playlists()->updateExistingPivot($playlistOne->id, ['sort' => 2]);

    expect(PlaylistGenerateController::getChannelQuery($merged)->get()->pluck('title')->all())
        ->toBe(['Two', 'One']);
});

it('orders merged playlist live groups via getMergedPlaylistGroupsQuery by source playlist attach order', function () {
    $playlistOne = Playlist::factory()->for($this->user)->create();
    $playlistTwo = Playlist::factory()->for($this->user)->create();

    // Deliberately give playlist two's group a *lower* raw sort_order so a naive
    // "ORDER BY groups.sort_order" would incorrectly surface it before playlist one's.
    $groupOne = Group::factory()->for($playlistOne)->for($this->user)->create(['sort_order' => 100, 'name' => 'One Group']);
    $groupTwo = Group::factory()->for($playlistTwo)->for($this->user)->create(['sort_order' => 1, 'name' => 'Two Group']);

    Channel::factory()->for($this->user)->for($playlistOne)->for($groupOne)->create(['enabled' => true, 'is_vod' => false]);
    Channel::factory()->for($this->user)->for($playlistTwo)->for($groupTwo)->create(['enabled' => true, 'is_vod' => false]);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($playlistOne->id);
    $merged->playlists()->attach($playlistTwo->id);

    $names = PlaylistGenerateController::getMergedPlaylistGroupsQuery($merged, isVod: false)->get()->pluck('name')->all();

    expect($names)->toBe(['One Group', 'Two Group']);
});

it('excludes groups with no enabled channels of the requested type from getMergedPlaylistGroupsQuery', function () {
    $playlist = Playlist::factory()->for($this->user)->create();

    $liveGroup = Group::factory()->for($playlist)->for($this->user)->create(['name' => 'Live Group']);
    $vodGroup = Group::factory()->for($playlist)->for($this->user)->create(['name' => 'VOD Group']);
    $disabledGroup = Group::factory()->for($playlist)->for($this->user)->create(['name' => 'Disabled Group']);

    Channel::factory()->for($this->user)->for($playlist)->for($liveGroup)->create(['enabled' => true, 'is_vod' => false]);
    Channel::factory()->for($this->user)->for($playlist)->for($vodGroup)->create(['enabled' => true, 'is_vod' => true]);
    Channel::factory()->for($this->user)->for($playlist)->for($disabledGroup)->create(['enabled' => false, 'is_vod' => false]);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($playlist->id);

    $liveNames = PlaylistGenerateController::getMergedPlaylistGroupsQuery($merged, isVod: false)->get()->pluck('name')->all();
    $vodNames = PlaylistGenerateController::getMergedPlaylistGroupsQuery($merged, isVod: true)->get()->pluck('name')->all();

    expect($liveNames)->toBe(['Live Group']);
    expect($vodNames)->toBe(['VOD Group']);
});

it('uses the saved custom group order in getMergedPlaylistGroupsQuery when enabled', function () {
    $playlistOne = Playlist::factory()->for($this->user)->create();
    $playlistTwo = Playlist::factory()->for($this->user)->create();

    $groupOneA = Group::factory()->for($playlistOne)->for($this->user)->create(['sort_order' => 1, 'name' => 'One A', 'name_internal' => 'One A Group']);
    $groupTwoA = Group::factory()->for($playlistTwo)->for($this->user)->create(['sort_order' => 1, 'name' => 'Two A', 'name_internal' => 'Two A Group']);

    Channel::factory()->for($this->user)->for($playlistOne)->for($groupOneA)->create(['enabled' => true, 'is_vod' => false, 'group_internal' => 'One A Group']);
    Channel::factory()->for($this->user)->for($playlistTwo)->for($groupTwoA)->create(['enabled' => true, 'is_vod' => false, 'group_internal' => 'Two A Group']);

    $merged = MergedPlaylist::factory()->for($this->user)->create(['group_order_custom' => true]);
    $merged->playlists()->attach($playlistOne->id);
    $merged->playlists()->attach($playlistTwo->id);
    storeMergedPlaylistGroupSettings($merged, [
        ['group' => $groupTwoA],
        ['group' => $groupOneA],
    ]);

    $names = PlaylistGenerateController::getMergedPlaylistGroupsQuery($merged, isVod: false)->get()->pluck('name')->all();

    expect($names)->toBe(['Two A', 'One A']);
});

it('excludes a group disabled in the saved group order from getChannelQuery and getMergedPlaylistGroupsQuery', function () {
    $playlistOne = Playlist::factory()->for($this->user)->create();
    $playlistTwo = Playlist::factory()->for($this->user)->create();

    $groupOneA = Group::factory()->for($playlistOne)->for($this->user)->create(['sort_order' => 1, 'name' => 'One A', 'name_internal' => 'One A Group']);
    $groupTwoA = Group::factory()->for($playlistTwo)->for($this->user)->create(['sort_order' => 1, 'name' => 'Two A', 'name_internal' => 'Two A Group']);

    Channel::factory()->for($this->user)->for($playlistOne)->for($groupOneA)->create([
        'enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'One A', 'group_internal' => 'One A Group',
    ]);
    Channel::factory()->for($this->user)->for($playlistTwo)->for($groupTwoA)->create([
        'enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'Two A', 'group_internal' => 'Two A Group',
    ]);

    $merged = MergedPlaylist::factory()->for($this->user)->create(['group_order_custom' => true]);
    $merged->playlists()->attach($playlistOne->id);
    $merged->playlists()->attach($playlistTwo->id);
    storeMergedPlaylistGroupSettings($merged, [
        ['group' => $groupOneA, 'enabled' => false],
        ['group' => $groupTwoA, 'enabled' => true],
    ]);

    $titles = PlaylistGenerateController::getChannelQuery($merged)->get()->pluck('title')->all();
    $groupNames = PlaylistGenerateController::getMergedPlaylistGroupsQuery($merged, isVod: false)->get()->pluck('name')->all();

    expect($titles)->toBe(['Two A']);
    expect($groupNames)->toBe(['Two A']);
});

it('does not mutate the source playlist Group/Channel enabled state, or affect a sibling merged playlist, when the group manager saves a disabled group', function () {
    $this->actingAs($this->user);

    $sharedPlaylist = Playlist::factory()->for($this->user)->create();
    $otherPlaylist = Playlist::factory()->for($this->user)->create();

    $sharedGroup = Group::factory()->for($sharedPlaylist)->for($this->user)->create([
        'sort_order' => 1, 'name' => 'Sports (US)', 'name_internal' => 'Sports US', 'enabled' => true,
    ]);
    $otherGroup = Group::factory()->for($otherPlaylist)->for($this->user)->create([
        'sort_order' => 1, 'name' => 'News', 'name_internal' => 'News',
    ]);

    $sharedChannel = Channel::factory()->for($this->user)->for($sharedPlaylist)->for($sharedGroup)->create([
        'enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'Sports Channel', 'group_internal' => 'Sports US',
    ]);
    Channel::factory()->for($this->user)->for($otherPlaylist)->for($otherGroup)->create([
        'enabled' => true, 'is_vod' => false, 'sort' => 1, 'title' => 'News Channel', 'group_internal' => 'News',
    ]);

    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($sharedPlaylist->id);
    $merged->playlists()->attach($otherPlaylist->id);

    // A sibling merged playlist also includes the shared source playlist, and must
    // remain unaffected by whatever gets disabled on the first merged playlist.
    $sibling = MergedPlaylist::factory()->for($this->user)->create();
    $sibling->playlists()->attach($sharedPlaylist->id);

    Livewire::test(MergedPlaylistGroupManager::class, ['record' => $merged])
        ->set('customOrderEnabled', true)
        ->call('toggleGroup', $sharedGroup->id)
        ->call('save');

    $merged->refresh();

    $sportsEntry = DB::table('merged_playlist_group_settings')
        ->where('merged_playlist_id', $merged->id)
        ->where('group_id', $sharedGroup->id)
        ->first();
    expect($sportsEntry)->not->toBeNull();
    expect((bool) $sportsEntry->enabled)->toBeFalse();

    // The edited merged playlist's own output excludes the disabled group's channel.
    expect(PlaylistGenerateController::getChannelQuery($merged)->get()->pluck('title')->all())
        ->toBe(['News Channel']);

    // The source playlist's own Group/Channel rows are untouched.
    expect($sharedGroup->refresh()->enabled)->toBeTrue();
    expect($sharedChannel->refresh()->enabled)->toBeTrue();

    // A sibling merged playlist that also includes the shared source playlist still
    // sees the channel, since it has its own (empty) group order.
    expect(PlaylistGenerateController::getChannelQuery($sibling)->get()->pluck('title')->all())
        ->toBe(['Sports Channel']);
});

it('prevents a different user from opening the group manager', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $merged = MergedPlaylist::factory()->for($owner)->create();

    $this->actingAs($intruder);

    Livewire::test(MergedPlaylistGroupManager::class, ['record' => $merged])
        ->assertForbidden();
});

it('uses stable group ids when a provider group name contains quotes', function () {
    $this->actingAs($this->user);

    $playlist = Playlist::factory()->for($this->user)->create();
    $group = Group::factory()->for($playlist)->for($this->user)->create([
        'name' => 'Sports \'); $wire.deleteEverything(); //',
        'name_internal' => 'Sports \'); $wire.deleteEverything(); //',
    ]);
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($playlist->id);

    Livewire::test(MergedPlaylistGroupManager::class, ['record' => $merged])
        ->assertSee($group->name)
        ->set('customOrderEnabled', true)
        ->call('toggleGroup', $group->id)
        ->call('save');

    expect(
        DB::table('merged_playlist_group_settings')
            ->where('merged_playlist_id', $merged->id)
            ->where('group_id', $group->id)
            ->value('enabled')
    )->toBe(0);
});

it('locks authoritative group state against client-side tampering', function () {
    $this->actingAs($this->user);

    $playlist = Playlist::factory()->for($this->user)->create();
    $group = Group::factory()->for($playlist)->for($this->user)->create();
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($playlist->id);

    expect(fn () => Livewire::test(MergedPlaylistGroupManager::class, ['record' => $merged])
        ->set('groupOrder', [$group->id + 999]))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('rejects invalid regular expressions without suppressing the validation error', function () {
    $this->actingAs($this->user);

    $merged = MergedPlaylist::factory()->for($this->user)->create();

    Livewire::test(MergedPlaylistGroupManager::class, ['record' => $merged])
        ->set('ruleMatchType', 'regex')
        ->set('rulePattern', '[invalid')
        ->call('addRule')
        ->assertHasErrors(['rulePattern']);
});

it('persists large group lists without generating per-group query bindings', function () {
    $this->actingAs($this->user);

    $playlist = Playlist::factory()->for($this->user)->create();
    Group::factory()
        ->count(400)
        ->for($playlist)
        ->for($this->user)
        ->sequence(fn ($sequence): array => [
            'name' => "Group {$sequence->index}",
            'name_internal' => "Group {$sequence->index}",
            'sort_order' => $sequence->index,
        ])
        ->create();
    $merged = MergedPlaylist::factory()->for($this->user)->create();
    $merged->playlists()->attach($playlist->id);

    Livewire::test(MergedPlaylistGroupManager::class, ['record' => $merged])
        ->set('customOrderEnabled', true)
        ->call('save');

    expect(
        DB::table('merged_playlist_group_settings')
            ->where('merged_playlist_id', $merged->id)
            ->count()
    )->toBe(400);
});
