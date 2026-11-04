<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Dealership;
use App\Models\ServiceType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a bookable demo dealership with its staff, bays, service catalogue and customers.
     */
    public function run(): void
    {
        $dealership = Dealership::create([
            'name' => 'Keyloop Da Nang',
            'timezone' => 'Asia/Ho_Chi_Minh',
            'opens_at' => '08:00:00',
            'closes_at' => '18:00:00',
        ]);

        foreach ([
            ['name' => 'Oil change', 'duration_minutes' => 60, 'required_skill' => 'general'],
            ['name' => 'Brake inspection', 'duration_minutes' => 90, 'required_skill' => 'brakes'],
            ['name' => 'EV battery check', 'duration_minutes' => 120, 'required_skill' => 'ev'],
        ] as $serviceType) {
            ServiceType::create($serviceType);
        }

        foreach ([
            'An Nguyen' => ['general'],
            'Binh Tran' => ['general', 'brakes'],
            'Chi Le' => ['general', 'brakes'],
            'Dung Pham' => ['general', 'ev'],
        ] as $name => $skills) {
            $technician = $dealership->technicians()->create(['name' => $name]);

            foreach ($skills as $skill) {
                $technician->skills()->create(['skill' => $skill]);
            }
        }

        foreach (['Bay 1', 'Bay 2', 'Bay 3'] as $bayName) {
            $dealership->serviceBays()->create(['name' => $bayName]);
        }

        foreach ([
            [
                'customer' => ['name' => 'Hoa Vo', 'email' => 'hoa.vo@example.com', 'phone' => '+84905111222'],
                'vehicles' => [
                    ['vin' => 'MR0FB8CD6N0123456', 'make' => 'Toyota', 'model' => 'Hilux', 'year' => 2022],
                    ['vin' => 'RLLV2ACE6PH000101', 'make' => 'VinFast', 'model' => 'VF 8', 'year' => 2023],
                ],
            ],
            [
                'customer' => ['name' => 'Khanh Dang', 'email' => 'khanh.dang@example.com', 'phone' => '+84905333444'],
                'vehicles' => [
                    ['vin' => 'MRHRW1859NP400213', 'make' => 'Honda', 'model' => 'CR-V', 'year' => 2022],
                ],
            ],
            [
                'customer' => ['name' => 'Linh Bui', 'email' => 'linh.bui@example.com', 'phone' => '+84905555666'],
                'vehicles' => [
                    ['vin' => 'MNBMA1CF2PW702345', 'make' => 'Ford', 'model' => 'Ranger', 'year' => 2023],
                    ['vin' => 'MR2B29F33N1056789', 'make' => 'Toyota', 'model' => 'Vios', 'year' => 2022],
                ],
            ],
        ] as $demoCustomer) {
            $customer = Customer::create($demoCustomer['customer']);

            foreach ($demoCustomer['vehicles'] as $vehicle) {
                $customer->vehicles()->create($vehicle);
            }
        }
    }
}
