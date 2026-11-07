<?php
namespace App\Modules\Leave\Filament\Resources\Leaves\Schemas;

use App\Modules\Leave\Models\Leave;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveAttachment;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Actions\{ActionGroup, ViewAction, EditAction, DeleteAction, BulkActionGroup, DeleteBulkAction};
class LeaveTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(
                Leave::query()
                    ->with(['employee'])

                    ->latest()
            )
            ->columns([
                TextColumn::make('employee.employee_code')
                    ->label('Employee code')
                    ->searchable(

                    )
                    ->sortable(),
                TextColumn::make('employee.name')
                    ->label('Employee')
                    ->searchable([
                        'first_name',
                        'last_name',
                    ])
                    ->sortable([
                        'first_name',
                        'last_name',
                    ]),
                TextColumn::make('leave_type')
                    ->label('Leave Type')
                    ->searchable(),
                TextColumn::make('start_date')
                    ->date()
                    ->label('Start Date'),
                TextColumn::make('end_date')
                    ->date()
                    ->label('End Date'),
                TextColumn::make('duration')
                    ->label('Days'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'Pending' => 'warning',
                        'Approved' => 'success',
                        'Rejected' => 'danger',
                        default => 'secondary',
                    })
                    ->label('Status')
                ,
                TextColumn::make('attachment')
                    ->label('Evidence')
                    ->state(fn(Leave $record) => $record->attachment
                        ? 'View'
                        : (LeaveType::requiresEvidence($record->leave_type) ? 'Missing' : '-'))
                    ->color(fn(Leave $record) => $record->attachment ? 'primary' : (LeaveType::requiresEvidence($record->leave_type) ? 'danger' : null))
                    ->url(fn(Leave $record) => LeaveAttachment::url($record))
                    ->openUrlInNewTab(),
                TextColumn::make('rejection_reason')
                    ->label('Rejection Reason')
                    ->default('N/A')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(50)
                    ->default('N/A')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Created At'),
            ])
            ->filters(
                [
                    //
                    SelectFilter::make('employee_id')
                        ->label('Employee')
                        ->searchable()
                        ->options(
                            // Employee::all()->pluck('full_name', 'id')
                            Leave::query()
                                ->with('employee')
                                ->get()
                                ->pluck('employee.name', 'employee.id')
                        )
                        ->default(null),
                    SelectFilter::make('status')
                        ->label('Status')
                        ->options([
                            'Pending' => 'Pending',
                            'Approved' => 'Approved',
                            'Rejected' => 'Rejected',
                        ])
                        ->default(null),
                    SelectFilter::make('leave_type')
                        ->label('Leave Type')
                        ->options(fn() => LeaveType::orderBy('id')->pluck('name', 'name'))
                        ->default(null),

                ]

            )
            ->recordActions([
                ActionGroup::make([

                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),

                ]),
            ]);

    }
}