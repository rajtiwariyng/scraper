<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scraper Configuration
    |--------------------------------------------------------------------------
    */

    'timeout' => env('SCRAPER_TIMEOUT', 30),
    'retries' => env('SCRAPER_RETRIES', 3),
    'delay_min' => env('SCRAPER_DELAY_MIN', 2),
    'delay_max' => env('SCRAPER_DELAY_MAX', 7),

    'user_agent' => env('SCRAPER_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Safari/537.36'),

    /*
    |--------------------------------------------------------------------------
    | Platform URLs
    |--------------------------------------------------------------------------
    */

    'platforms' => [
        'amazon' => [
            'name' => 'Amazon India',
            'base_url' => 'https://www.amazon.in',
            'category_urls' => [
                //printer
                'https://www.amazon.in/s?k=printer&i=computers&rh=n%3A976392031%2Cp_123%3A233970%257C242668%257C308445%257C359121&dc&ds=v1%3Au9fHF8NkLhS5YXyr6yWNrTnqZL%2FbyZSwRt8sh1RGVF0&crid=FA8FJ3BLCAQH&qid=1762351572&rnid=91049095031&sprefix=printer%2Ccomputers%2C268&ref=sr_nr_p_123_5',
                //mobile
                'https://www.amazon.in/s?k=mobile&rh=n%3A22736673031%2Cp_123%3A13145%257C146762%257C338933%257C339703%257C46655%257C559198%257C568349%257C940997&dc&crid=CWCF2G09NZWF&qid=1774402082&rnid=91049095031&sprefix=%2Caps%2C330&ref=sr_nr_p_123_11&ds=v1%3ADNVkwH5kbHHkkl0G%2BqrJ%2FWaxL3JaT5Bvzf2E8%2BJm77E',
                //diapers and wipes
                'https://www.amazon.in/s?k=Diapers&rh=n%3A22737052031%2Cp_123%3A13483%257C200890%257C236240%257C318757%257C368300%257C465996%257C599266%257C94291&dc&crid=G6Y6MML9TSK1&qid=1775035383&rnid=91049095031&sprefix=diapers%2Caps%2C546&ref=sr_nr_p_123_8&ds=v1%3A2oHWsIIxo9MsC%2F61ZC3vBSY0O1B2duOmR73YSuX%2Bxts',
                'https://www.amazon.in/s?k=wipes&rh=n%3A22735827031%2Cp_123%3A13483%257C200890%257C377901%257C465996%257C599266%257C94291&dc&crid=JEZ2DWDY976N&qid=1775036195&rnid=91049095031&sprefix=%2Caps%2C378&ref=sr_nr_p_123_6&ds=v1%3AzXTYxEajgfKb3x0BmtBTSwVDA6%2Bippg5ZWGOMOvTLO0',
                //detergent
                'https://www.amazon.in/s?k=detergent&rh=n%3A22737389031%2Cp_123%3A232407%257C232431%257C336400%257C418076%257C679243%257C684582&dc&crid=AV2T5TTB5EFH&qid=1775111583&rnid=91049095031&sprefix=detergent%2Caps%2C421&ref=sr_nr_p_123_12&ds=v1%3AeU8kmHkHvV%2Beqg0EHpjYYpYGY3%2Btl7cFGSEuw%2BVgnzA',
                'https://www.amazon.in/s?rh=n%3A22420115031%2Cp_123%3A808592&dc&qid=1775140366&rnid=91049095031&ref=sr_nr_p_123_4',
            ]
        ],
        'amazon_jp' => [
            'name' => 'Amazon Japan',
            'base_url' => 'https://www.amazon.co.jp',
            'category_urls' => [
                // Printers
                'https://www.amazon.co.jp/s?k=printer&rh=p_89%3AHP%7CCanon%7CEpson%7CBrother&dc',
                // Mobiles
                'https://www.amazon.co.jp/s?k=smartphone&rh=p_89%3ASamsung%7CApple%7CSony%7CSHARP&dc',
                // Diapers
                'https://www.amazon.co.jp/s?k=diaper&rh=p_89%3APampers%7CHuggies%7CUnicharm&dc',
                // Wipes
                'https://www.amazon.co.jp/s?k=baby+wipes&rh=p_89%3APampers%7CHuggies%7CUnicharm&dc',
                // Detergent
                'https://www.amazon.co.jp/s?k=laundry+detergent&rh=p_89%3ALion%7CAriel%7CTide&dc',
            ]
        ],
        'amazon_sa' => [
            'name' => 'Amazon KSA',
            'base_url' => 'https://www.amazon.sa',
            'category_urls' => [
                // Sleepwell mattresses
                'https://www.amazon.sa/s?k=sleepwell&rh=n%3A16856304031&ref=nb_sb_noss',
                // Kurlon mattresses
                'https://www.amazon.sa/s?k=Kurlon&crid=2OK8X428U41I&sprefix=kurlon%2Caps%2C221&ref=nb_sb_noss_2',
            ],
        ],
        'flipkart' => [
            'name' => 'Flipkart',
            'base_url' => 'https://www.flipkart.com',
            'cookies' => [
                'deliveryPincode' => '110001',
            ],
            'wait_for_selector' => 'h1, [data-id]',
            'wait_for_selector_timeout' => 8000,
            'category_urls' => [
                'https://www.flipkart.com/computers/computer-peripherals/printers/pr?sid=6bo%2Ctia%2Cx4x&q=printer&otracker=categorytree&p%5B%5D=facets.brand%255B%255D%3DHP&p%5B%5D=facets.brand%255B%255D%3DCanon&p%5B%5D=facets.brand%255B%255D%3DEpson&p%5B%5D=facets.brand%255B%255D%3Dbrother',
                'https://www.flipkart.com/mobiles/pr?sid=tyy%2C4io&q=mobile&otracker=categorytree&p%5B%5D=facets.brand%255B%255D%3DMOTOROLA&p%5B%5D=facets.brand%255B%255D%3Dvivo&p%5B%5D=facets.brand%255B%255D%3DOPPO&p%5B%5D=facets.brand%255B%255D%3DSamsung&p%5B%5D=facets.brand%255B%255D%3Drealme&p%5B%5D=facets.brand%255B%255D%3DREDMI&p%5B%5D=facets.brand%255B%255D%3DLAVA&page=26',
                 //diaper and wipe
                'https://www.flipkart.com/search?q=Diapers&otracker=search&otracker1=search&marketplace=FLIPKART&as-show=on&as=off&p%5B%5D=facets.brand%255B%255D%3DPampers&p%5B%5D=facets.brand%255B%255D%3DMamyPoko&p%5B%5D=facets.brand%255B%255D%3DHuggies&p%5B%5D=facets.brand%255B%255D%3DHIMALAYA&p%5B%5D=facets.brand%255B%255D%3DLuvLap&p%5B%5D=facets.brand%255B%255D%3DLittle%2527s&p%5B%5D=facets.brand%255B%255D%3DMeeMee&p%5B%5D=facets.brand%255B%255D%3DBambo%2BNature',
                'https://www.flipkart.com/search?q=wipes&otracker=search&otracker1=search&marketplace=FLIPKART&as-show=on&as=off&p%5B%5D=facets.brand%255B%255D%3DHIMALAYA&p%5B%5D=facets.brand%255B%255D%3DLuvLap&p%5B%5D=facets.brand%255B%255D%3DMeeMee&p%5B%5D=facets.brand%255B%255D%3DLittle%2527s&p%5B%5D=facets.brand%255B%255D%3DPampers&p%5B%5D=facets.brand%255B%255D%3DMamyPoko&p%5B%5D=facets.brand%255B%255D%3DMamaearth&p%5B%5D=facets.brand%255B%255D%3DPigeon',
                'https://www.flipkart.com/search?q=detergent&otracker=search&otracker1=search&marketplace=FLIPKART&as-show=on&as=off&p%5B%5D=facets.brand%255B%255D%3DSurf%2Bexcel&p%5B%5D=facets.brand%255B%255D%3DAriel&p%5B%5D=facets.brand%255B%255D%3DTide&p%5B%5D=facets.brand%255B%255D%3DRin&p%5B%5D=facets.brand%255B%255D%3DGhadi&p%5B%5D=facets.brand%255B%255D%3DWheel&p%5B%5D=facets.brand%255B%255D%3DComfort&p%5B%5D=facets.brand%255B%255D%3DNirma',
            ]
        ],
        'vijaysales' => [
            'name' => 'VijaySales',
            'base_url' => 'https://www.vijaysales.com',
            'category_urls' => [
                'https://www.vijaysales.com/c/smartphones',
                'https://www.vijaysales.com/c/printers',

            ]
        ],
        'reliancedigital' => [
            'name' => 'Reliance Digital',
            'base_url' => 'https://www.reliancedigital.in',
            'category_urls' => [
                'https://www.reliancedigital.in/collection/hp-printers',
                'https://www.reliancedigital.in/collection/canon-printers',
                'https://www.reliancedigital.in/collection/brother-printers',
                'https://www.reliancedigital.in/collection/epson-printers',
                'https://www.reliancedigital.in/collection/samsung-mobiles',
                'https://www.reliancedigital.in/collection/vivo-mobiles',
                'https://www.reliancedigital.in/collection/oppo-mobiles',
                'https://www.reliancedigital.in/collection/motorola-mobiles',
                'https://www.reliancedigital.in/collection/redmi-mobiles',
                'https://www.reliancedigital.in/collection/realme-mobiles',
            ]
        ],
        'croma' => [
            'name' => 'Croma',
            'base_url' => 'https://www.croma.com',
            'category_urls' => [
                'https://www.croma.com/computers-tablets/printers/c/31',
                'https://www.croma.com/searchB?q=mobile%3Arelevance%3ASG-ManufacturerDetails-Brand%3ASamsung%3ASG-ManufacturerDetails-Brand%3AVivo%3ASG-ManufacturerDetails-Brand%3ARealme%3ASG-ManufacturerDetails-Brand%3AOppo%3ASG-ManufacturerDetails-Brand%3ARedmi%3ASG-ManufacturerDetails-Brand%3AXiaomi&text=mobile',

            ]
        ],
        'zepto' => [
            'name' => 'Zepto',
            'base_url' => 'https://www.zepto.com',
            'enabled' => true,
            'category_urls' => [
                'https://www.zepto.com/cn/baby-care/baby-diapering/cid/0118c4f5-750c-4929-a734-b4ef454e265b/scid/b998e39c-6948-42f2-84bb-947f07f2ceca',
                'https://www.zepto.com/cn/baby-care/baby-wipes/cid/0118c4f5-750c-4929-a734-b4ef454e265b/scid/2b24bfa9-6ef9-41a4-8bf9-694ef4d01a44',
                'https://www.zepto.com/cn/cleaning-essentials/liquid-detergents-additives/cid/1a7e46a8-e627-450f-8960-490b550eeee6/scid/dfb37880-b40f-4783-9502-a56e12edbabc',
                'https://www.zepto.com/cn/cleaning-essentials/winter-laundry/cid/1a7e46a8-e627-450f-8960-490b550eeee6/scid/db3891eb-ce77-43ea-8700-70832abc1ec1',
                'https://www.zepto.com/cn/cleaning-essentials/detergent-powder-bars/cid/1a7e46a8-e627-450f-8960-490b550eeee6/scid/6d8da507-b86b-48b8-ad99-9e5a5c61a48f',
                'https://www.zepto.com/cn/cleaning-essentials/laundry-additives/cid/1a7e46a8-e627-450f-8960-490b550eeee6/scid/a625cca0-c8fe-495b-b4d7-c2c802d6d05e',
                'https://www.zepto.com/cn/cleaning-essentials/dishwash-gels-bars/cid/1a7e46a8-e627-450f-8960-490b550eeee6/scid/f370ad06-83f2-4831-95dd-644c675e068f',
                
            ]
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Scraping Schedule  
    |--------------------------------------------------------------------------
    */

    'schedule' => [
        'enabled' => env('SCRAPER_SCHEDULE_ENABLED', true),
        'interval_hours' => env('SCRAPER_INTERVAL_HOURS', 168), // 7 days
        'max_execution_time' => env('SCRAPER_MAX_EXECUTION_TIME', 86400), // 24 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Data Validation Rules
    |--------------------------------------------------------------------------
    */

    'validation' => [
        'required_fields' => ['sku', 'title', 'platform'],
        'max_description_length' => 5000,
        'max_title_length' => 500,
        'max_brand_length' => 100,
        'max_model_length' => 200,
        'price_range' => [
            'min' => 10,  // Minimum product price in INR
            'max' => 1600000  // Maximum product price in INR
        ],
        'price_range_by_platform' => [
            'amazon_jp' => [
                'min' => 100,
                'max' => 5000000,
            ],
            'amazon_sa' => [
                'min' => 50,    // SAR
                'max' => 20000, // SAR
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Processing
    |--------------------------------------------------------------------------
    */

    'images' => [
        'download_enabled' => env('SCRAPER_DOWNLOAD_IMAGES', false),
        'max_images_per_product' => 15,
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'max_file_size' => 5 * 1024 * 1024, // 5MB
        'storage_path' => 'images/products'
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'enabled' => true,
        'level' => env('SCRAPER_LOG_LEVEL', 'info'),
        'retention_days' => 30,
        'detailed_errors' => env('SCRAPER_DETAILED_ERRORS', true)
    ]

];