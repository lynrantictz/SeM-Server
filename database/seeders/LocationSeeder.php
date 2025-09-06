<?php

namespace Database\Seeders;

use App\Models\Location\Country;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $countries = [
            [   'name' => 'The United Republic of Tanzania',
                'iso2' => 'TZ',
                'iso3' => 'TZA',
                'currency' => 'TZS',
                'phone_code' => '255',
                'flag' => 'flags/tz.jpg',
                'cities' => [
                    ['name' => 'Arusha',
                        'districts' => [
                            ['name' => 'Meru'],
                            ['name' => 'Arusha City'],
                            ['name' => 'Arusha District'],
                            ['name' => 'Karatu'],
                            ['name' => 'Longido'],
                            ['name' => 'Monduli'],
                            ['name' => 'Ngorongoro'],
                        ]
                    ],
                    ['name' => 'Dar es Salaam',
                        'districts' => [
                            ['name' => 'Ilala'],
                            ['name' => 'Kinondoni'],
                            ['name' => 'Temeke'],
                            ['name' => 'Kigamboni'],
                            ['name' => 'Ubungo'],
                        ]
                    ],

                    ['name' => 'Dodoma',
                        'districts' => [
                            ['name' => 'Bahi'],
                            ['name' => 'Chamwino'],
                            ['name' => 'Chemba'],
                            ['name' => 'Dodoma Municipal'],
                            ['name' => 'Kondoa'],
                            ['name' => 'Kongwa'],
                            ['name' => 'Mpwapwa'],
                        ]
                    ],
                    ['name' => 'Geita',
                        'districts' => [
                            ['name' => 'Bukombe'],
                            ['name' => 'Chato'],
                            ['name' => 'Geita District Council'],
                            ['name' => 'Mbogwe'],
                            ['name' => 'Nyang\'hwale'],
                        ]
                    ],
                    ['name' => 'Iringa',
                        'districts' => [
                            ['name' => 'Iringa District'],
                            ['name' => 'Iringa Municipal'],
                            ['name' => 'Kilolo'],
                            ['name' => 'Mafinga Town'],
                            ['name' => 'Mufindi'],
                        ]
                    ],
                    ['name' => 'Kagera',
                        'districts' => [
                            ['name' => 'Biharamulo'],
                            ['name' => 'Bukoba District'],
                            ['name' => 'Bukoba Municipal'],
                            ['name' => 'Karagwe'],
                            ['name' => 'Kyerwa'],
                            ['name' => 'Missenyi'],
                            ['name' => 'Muleba'],
                            ['name' => 'Ngara'],
                        ]
                    ],
                    ['name' => 'Katavi',
                        'districts' => [
                            ['name' => 'Mlele'],
                            ['name' => 'Mpanda'],
                            ['name' => 'Mpanda Town'],
                        ]
                    ],
                    ['name' => 'Kigoma',
                        'districts' => [
                            ['name' => 'Buhigwe'],
                            ['name' => 'Kakonko'],
                            ['name' => 'Kasulu District'],
                            ['name' => 'Kasulu Town'],
                            ['name' => 'Kibondo'],
                            ['name' => 'Kigoma District'],
                            ['name' => 'Kigoma-Ujiji Municipal'],
                            ['name' => 'Uvinza'],
                        ]
                    ],
                    ['name' => 'Kilimanjaro',
                        'districts' => [
                            ['name' => 'Hai'],
                            ['name' => 'Moshi District'],
                            ['name' => 'Moshi Municipal'],
                            ['name' => 'Mwanga'],
                            ['name' => 'Rombo'],
                            ['name' => 'Same'],
                            ['name' => 'Siha'],
                        ]
                    ],
                    ['name' => 'Lindi',
                        'districts' => [
                            ['name' => 'Kilwa'],
                            ['name' => 'Lindi District'],
                            ['name' => 'Lindi Municipal'],
                            ['name' => 'Liwale'],
                            ['name' => 'Nachingwea'],
                            ['name' => 'Ruangwa'],
                        ]
                    ],
                    ['name' => 'Manyara',
                        'districts' => [
                            ['name' => 'Babati Town'],
                            ['name' => 'Babati District'],
                            ['name' => 'Hanang'],
                            ['name' => 'Kiteto'],
                            ['name' => 'Mbulu'],
                            ['name' => 'Simanjiro'],
                        ]
                    ],
                    ['name' => 'Mara',
                        'districts' => [
                            ['name' => 'Bunda Town Council'],
                            ['name' => 'Butiama'],
                            ['name' => 'Musoma District'],
                            ['name' => 'Musoma Municipal'],
                            ['name' => 'Rorya'],
                            ['name' => 'Serengeti'],
                            ['name' => 'Tarime District'],
                        ]
                    ],
                    ['name' => 'Mbeya',
                        'districts' => [
                            ['name' => 'Busokelo'],
                            ['name' => 'Chunya'],
                            ['name' => 'Kyela'],
                            ['name' => 'Mbarali'],
                            ['name' => 'Mbeya City'],
                            ['name' => 'Mbeya District'],
                            ['name' => 'Rungwe'],
                        ]
                    ],
                    ['name' => 'Morogoro',
                        'districts' => [
                            ['name' => 'Gairo'],
                            ['name' => 'Kilombero'],
                            ['name' => 'Kilosa'],
                            ['name' => 'Morogoro District'],
                            ['name' => 'Morogoro Municipal'],
                            ['name' => 'Mvomero'],
                            ['name' => 'Ulanga'],
                        ]
                    ],
                    ['name' => 'Mtwara',
                        'districts' => [
                            ['name' => 'Masasi District'],
                            ['name' => 'Masasi Town'],
                            ['name' => 'Mtwara District'],
                            ['name' => 'Mtwara Municipal'],
                            ['name' => 'Nanyumbu'],
                            ['name' => 'Newala'],
                            ['name' => 'Tandahimba'],
                        ]
                    ],
                    ['name' => 'Mwanza',
                        'districts' => [
                            ['name' => 'Ilemela'],
                            ['name' => 'Kwimba'],
                            ['name' => 'Magu'],
                            ['name' => 'Misungwi'],
                            ['name' => 'Nyamagana'],
                            ['name' => 'Sengerema'],
                            ['name' => 'Ukerewe'],
                            ['name' => 'Buchosa'],
                        ]
                    ],
                    ['name' => 'Njombe',
                        'districts' => [
                            ['name' => 'Ludewa'],
                            ['name' => 'Makambako'],
                            ['name' => 'Makete'],
                            ['name' => 'Njombe District'],
                            ['name' => 'Njombe Town'],
                            ['name' => 'Wanging\'ombe'],
                        ]
                    ],
                    ['name' => 'Pemba North',
                        'districts' => [
                            ['name' => 'Micheweni'],
                            ['name' => 'Wete'],
                        ]
                    ],
                    ['name' => 'Pemba South',
                        'districts' => [
                            ['name' => 'Chake Chake'],
                            ['name' => 'Mkoani'],
                        ]
                    ],
                    ['name' => 'Pwani',
                        'districts' => [
                            ['name' => 'Bagamoyo'],
                            ['name' => 'Kibaha District'],
                            ['name' => 'Kibaha Town'],
                            ['name' => 'Kisarawe'],
                            ['name' => 'Mafia'],
                            ['name' => 'Mkuranga'],
                            ['name' => 'Rufiji'],
                            ['name' => 'Chalinze'],
                        ]
                    ],
                    ['name' => 'Rukwa',
                        'districts' => [
                            ['name' => 'Kalambo'],
                            ['name' => 'Nkasi'],
                            ['name' => 'Sumbawanga District'],
                            ['name' => 'Sumbawanga Municipal'],
                        ]
                    ],
                    ['name' => 'Ruvuma',
                        'districts' => [
                            ['name' => 'Mbinga'],
                            ['name' => 'Songea District'],
                            ['name' => 'Songea Municipal'],
                            ['name' => 'Tunduru'],
                            ['name' => 'Namtumbo'],
                            ['name' => 'Nyasa'],
                            ['name' => 'Madaba'],
                        ]
                    ],
                    ['name' => 'Shinyanga',
                        'districts' => [
                            ['name' => 'Kahama Town'],
                            ['name' => 'Kahama District'],
                            ['name' => 'Kishapu'],
                            ['name' => 'Shinyanga District'],
                            ['name' => 'Shinyanga Municipal'],
                        ]
                    ],
                    ['name' => 'Simiyu',
                        'districts' => [
                            ['name' => 'Bariadi'],
                            ['name' => 'Busega'],
                            ['name' => 'Itilima'],
                            ['name' => 'Maswa'],
                            ['name' => 'Meatu'],
                        ]
                    ],
                    ['name' => 'Singida',
                        'districts' => [
                            ['name' => 'Ikungi'],
                            ['name' => 'Iramba'],
                            ['name' => 'Manyoni'],
                            ['name' => 'Mkalama'],
                            ['name' => 'Singida District'],
                            ['name' => 'Singida Municipal'],
                        ]
                    ],
                    ['name' => 'Tabora',
                        'districts' => [
                            ['name' => 'Igunga'],
                            ['name' => 'Kaliua'],
                            ['name' => 'Nzega'],
                            ['name' => 'Sikonge'],
                            ['name' => 'Tabora Municipal'],
                            ['name' => 'Urambo'],
                            ['name' => 'Uyui'],
                        ]
                    ],
                    ['name' => 'Tanga',
                        'districts' => [
                            ['name' => 'Handeni District'],
                            ['name' => 'Handeni Town'],
                            ['name' => 'Kilindi'],
                            ['name' => 'Korogwe Town'],
                            ['name' => 'Korogwe District'],
                            ['name' => 'Lushoto'],
                            ['name' => 'Muheza'],
                            ['name' => 'Mkinga'],
                            ['name' => 'Pangani'],
                            ['name' => 'Tanga City'],
                        ]
                    ],
                    ['name' => 'Zanzibar',
                        'districts' => [
                            ['name' => 'Zanzibar']
                        ]
                    ],
                    ['name' => 'Zanzibar Urban West',
                        'districts' => [
                            ['name' => 'Magharibi'],
                            ['name' => 'Mjini'],
                            ['name' => 'Zanzibar West District'],
                        ]
                    ],
                    ['name' => 'Songwe',
                        'districts' => [
                            ['name' => 'Songwe'],
                            ['name' => 'Mbozi'],
                            ['name' => 'Ileje'],
                            ['name' => 'Momba'],
                        ]
                    ],
                    ['name' => 'Unguja South',
                        'districts' => [
                            ['name' => 'Kati'],
                            ['name' => 'Kusini'],
                        ]
                    ],
                    ['name' => 'Unguja North',
                        'districts' => [
                            ['name' => 'Kaskazini A'],
                            ['name' => 'Kaskazini B'],
                        ]
                    ],
                    ['name' => 'Unguja Central',
                        'districts' => [
                            ['name' => 'Mkoani'],
                            ['name' => 'Micheweni'],
                            ['name' => 'Wete'],
                        ]
                    ],
                    ['name' => 'Zanzibar North',
                        'districts' => [
                            ['name' => 'Kaskazini A'],
                            ['name' => 'Kaskazini B'],
                        ]
                    ]
                ]
            ]
        ];
        foreach ($countries as $country) {
            $input = [
                'name' => $country['name'],
                'iso2' => $country['iso2'],
                'iso3' => $country['iso3'],
                'currency' => $country['currency'],
                'phone_code' => $country['phone_code'],
                'flag' => $country['flag'],
                'uuid' => Str::uuid(),
            ];
            $countryModel = Country::firstOrCreate(['name' => $country['name'], 'iso2' => $country['iso2'], 'iso3' => $country['iso3'], 'phone_code' => $country['phone_code']],
                $input
            );
            foreach ($country['cities'] as $city) {
                $cityModel = $countryModel->cities()->firstOrCreate(['name' => $city['name']]);
                foreach ($city['districts'] as $district) {
                    $cityModel->districts()->firstOrCreate(['name' => $district['name']]);
                }
            }

        }
    }
}
