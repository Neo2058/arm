<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Request;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // No change here
        return $data;
    }

    public function mount(): void
    {
        parent::mount();

        // Autofill from AccessRequest via query params
        $fio = Request::query('fio');
        $tabNumber = Request::query('tab_number');

        if ($fio || $tabNumber) {
            $this->form->fill([
                'name' => $fio,
                'profile' => [
                    'tab_number' => $tabNumber,
                ],
            ]);
        }
    }
}
