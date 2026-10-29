<?php

namespace App\Repositories\Academics;

use App\Models\Institution;

class InstitutionRepository
{
    public function getDefault(): Institution
    {
        return Institution::firstOrCreate(
            ['code' => 'MMS-001'],
            [
                'name_bn' => 'আদর্শ ইসলামিয়া মাদ্রাসা',
                'name_en' => 'Adarsha Islamia Madrasa',
                'address_bn' => 'সদর, বাংলাদেশ',
                'address_en' => 'Sadar, Bangladesh',
                'phone' => null,
                'default_locale' => 'bn-BD',
                'timezone' => 'Asia/Dhaka',
            ]
        );
    }

    public function update(Institution $institution, array $attributes): Institution
    {
        $institution->update($attributes);

        return $institution->fresh();
    }
}

