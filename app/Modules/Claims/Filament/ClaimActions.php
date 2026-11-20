<?php

namespace App\Modules\Claims\Filament;

use App\Models\Employee;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Services\ClaimApproval;
use App\Modules\Claims\Services\ClaimPayment;
use App\Modules\Claims\Services\ClaimSubmission;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * The actions on a claim, shared by the lists and the view pages of both panels. The visibility only
 * hides a button: each action is checked again by its service, on the current state of the claim.
 */
final class ClaimActions
{
    public static function submit(): Action
    {
        return Action::make('submit')
            ->label('Submit')
            ->icon('heroicon-o-paper-airplane')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Submit this claim?')
            ->modalDescription('Your supervisor and HR are asked to approve it. You cannot change it any more, unless you withdraw it.')
            ->visible(fn (Claim $record) => self::user() !== null && app(ClaimSubmission::class)->canEdit(self::user(), $record))
            ->action(fn (Claim $record, $livewire) => self::report(
                app(ClaimSubmission::class)->submit($record, self::user()),
                'Claim submitted',
                'The claim was not submitted',
                $livewire,
            ));
    }

    public static function withdraw(): Action
    {
        return Action::make('withdraw')
            ->label('Withdraw')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Withdraw this claim?')
            ->modalDescription('It goes back to draft: you can change it and submit it again.')
            ->visible(fn (Claim $record) => self::user() !== null && app(ClaimSubmission::class)->canWithdraw(self::user(), $record))
            ->action(fn (Claim $record, $livewire) => self::report(
                app(ClaimSubmission::class)->withdraw($record, self::user()),
                'Claim withdrawn',
                'The claim was not withdrawn',
                $livewire,
            ));
    }

    /**
     * @param  string  $indexUrl  where to go when the claim was deleted from its view page
     */
    public static function delete(string $indexUrl): Action
    {
        return Action::make('deleteDraft')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete this draft?')
            ->visible(fn (Claim $record) => self::user() !== null && app(ClaimSubmission::class)->canEdit(self::user(), $record))
            ->action(function (Claim $record, $livewire) use ($indexUrl) {
                $errors = app(ClaimSubmission::class)->delete($record, self::user());
                if ($errors !== []) {
                    Notification::make()->title('The claim was not deleted')->body(implode("\n", $errors))->danger()->send();

                    return;
                }
                Notification::make()->title('Draft deleted')->success()->send();
                if ($livewire instanceof ViewRecord) {
                    $livewire->redirect($indexUrl);
                }
            });
    }

    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon('heroicon-o-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(fn (Claim $record) => 'Approve the claim of '.$record->employee?->name.'?')
            ->modalDescription(fn (Claim $record) => $record->title.': '.Claim::formatAmount($record->total))
            ->visible(fn (Claim $record) => self::canDecide($record))
            ->action(fn (Claim $record, $livewire) => self::report(
                app(ClaimApproval::class)->decide($record, self::user(), Claim::APPROVED),
                'Claim approved',
                'The claim was not decided',
                $livewire,
            ));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->modalHeading(fn (Claim $record) => 'Reject the claim of '.$record->employee?->name.'?')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason of the rejection')
                    ->helperText('The employee receives it by email.')
                    ->required()
                    ->maxLength(1000),
            ])
            ->visible(fn (Claim $record) => self::canDecide($record))
            ->action(fn (Claim $record, array $data, $livewire) => self::report(
                app(ClaimApproval::class)->decide($record, self::user(), Claim::REJECTED, $data['reason'] ?? null),
                'Claim rejected',
                'The claim was not decided',
                $livewire,
            ));
    }

    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label('Mark paid')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalHeading(fn (Claim $record) => 'Mark the claim of '.$record->employee?->name.' as paid')
            ->modalDescription(fn (Claim $record) => $record->title.': '.Claim::formatAmount($record->total))
            ->schema([
                DatePicker::make('paid_at')->label('Paid on')->default(now())->maxDate(now())->required(),
                TextInput::make('payment_reference')
                    ->label('Payment reference')
                    ->helperText('For example the bank transfer reference.')
                    ->maxLength(255),
            ])
            ->visible(fn (Claim $record) => self::user() !== null && app(ClaimPayment::class)->canPay(self::user(), $record))
            ->action(fn (Claim $record, array $data, $livewire) => self::report(
                app(ClaimPayment::class)->markPaid($record, self::user(), $data['paid_at'] ?? null, $data['payment_reference'] ?? null),
                'Claim marked as paid',
                'The claim was not marked as paid',
                $livewire,
            ));
    }

    private static function canDecide(Claim $record): bool
    {
        $user = self::user();

        return $user !== null && app(ClaimApproval::class)->canDecide($user, $record);
    }

    private static function user(): ?Employee
    {
        $user = Auth::user();

        return $user instanceof Employee ? $user : null;
    }

    /**
     * @param  list<string>  $errors
     */
    private static function report(array $errors, string $success, string $failure, mixed $livewire): void
    {
        if ($errors !== []) {
            Notification::make()->title($failure)->body(implode("\n", $errors))->danger()->send();

            return;
        }

        Notification::make()->title($success)->success()->send();

        // The view page shows the claim as it was loaded: show the new state
        if ($livewire instanceof ViewRecord) {
            $livewire->getRecord()->refresh()->load(ClaimInfolist::relations());
        }
    }
}
