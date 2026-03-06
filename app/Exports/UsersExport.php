<?php
namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

class UsersExport implements FromCollection
{
    protected $users;

    public function __construct($users)
    {
        $this->users = collect($users);
    }

    public function collection()
    {
        return $this->users;
    }
}