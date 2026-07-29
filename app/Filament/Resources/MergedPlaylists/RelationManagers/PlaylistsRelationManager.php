<?php

namespace App\Filament\Resources\MergedPlaylists\RelationManagers;

use App\Enums\Status;
use App\Models\Playlist;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaylistsRelationManager extends RelationManager
{
    protected static string $relationship = 'playlists';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('enabled_channels')
                ->withCount('enabled_groups')
            )
            ->recordTitleAttribute('name')
            ->defaultSort(fn (Builder $query, string $direction): Builder => $query->orderByRaw("merged_playlist_playlist.sort {$direction}"))
            ->reorderable('merged_playlist_playlist.sort')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('groups_count')
                    ->label(__('Groups'))
                    ->counts('groups')
                    ->description(fn (Playlist $record): string => __('Enabled: :count', ['count' => $record->enabled_groups_count ?? '—']))
                    ->sortable(),
                TextColumn::make('channels_count')
                    ->label(__('Channels'))
                    ->counts('channels')
                    ->description(fn (Playlist $record): string => __('Enabled: :count', ['count' => $record->enabled_channels_count ?? '—']))
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->color(fn (Status $state) => $state->getColor()),
                TextColumn::make('synced')
                    ->label(__('Last Synced'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name'])
                    ->recordSelectOptionsQuery(
                        fn (Builder $query, $livewire) => $query
                            ->select(['id', 'name'])
                            ->where('user_id', $livewire->ownerRecord->user_id)
                            ->orderBy('name')
                    ),

                // Advanced attach when adding pivot values:
                // Tables\Actions\AttachAction::make()->schema(fn(Tables\Actions\AttachAction $action): array => [
                //     $action->getRecordSelect(),
                //     Forms\Components\TextInput::make('title')
                //         ->label(__('Title'))
                //         ->required(),
                // ]),
            ])
            ->recordActions([
                DetachAction::make()
                    ->icon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()->color('warning'),
                ]),
            ]);
    }
}
