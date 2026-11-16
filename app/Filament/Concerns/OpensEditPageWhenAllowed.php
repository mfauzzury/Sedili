<?php

namespace App\Filament\Concerns;

/**
 * For a view page: whoever is allowed to edit the record lands on the edit form straight away
 * (no extra click on "Edit"); the others keep the read-only page.
 */
trait OpensEditPageWhenAllowed
{
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $resource = static::getResource();
        if ($resource::hasPage('edit') && $resource::canEdit($this->getRecord())) {
            $this->redirect($resource::getUrl('edit', ['record' => $this->getRecord()]));
        }
    }
}
