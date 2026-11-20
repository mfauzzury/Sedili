<?php

namespace App\Modules\Claims\Filament\Portal\Pages;

use App\Modules\Claims\Filament\Portal\MyClaimResource;
use App\Modules\Claims\Models\Claim;
use App\Modules\Claims\Services\ClaimSubmission;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/**
 * New claim: saved as a draft, or saved and submitted at once.
 */
class CreateMyClaim extends CreateRecord
{
    protected static string $resource = MyClaimResource::class;

    protected static bool $canCreateAnother = false;

    public bool $submitAfterCreate = false;

    /**
     * The employee and the status are set here, whatever the form contains.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'employee_id' => Auth::id(),
            'status' => Claim::DRAFT,
        ];
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('createAndSubmit')
                ->label('Save and submit')
                ->action(function () {
                    $this->submitAfterCreate = true;
                    $this->create();
                }),
            $this->getCreateFormAction()->label('Save draft')->color('gray'),
            $this->getCancelFormAction(),
        ];
    }

    protected function afterCreate(): void
    {
        if (! $this->submitAfterCreate) {
            return;
        }

        /** @var Claim $claim */
        $claim = $this->getRecord();
        $errors = app(ClaimSubmission::class)->submit($claim, Auth::user());
        if ($errors !== []) {
            Notification::make()->title('The claim was saved as a draft')->body(implode("\n", $errors))->warning()->send();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        // afterCreate() ran first: the status tells whether the submission worked
        return $this->getRecord()->getAttribute('status') === Claim::SUBMITTED ? 'Claim submitted' : 'Draft saved';
    }

    protected function getRedirectUrl(): string
    {
        return MyClaimResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
