<?php
/**
 * South Africa's municipal structure: province -> district municipalities
 * (and metros, which act as their own district+local authority) -> local
 * municipalities.
 *
 * Source: cross-checked against the 2022 census municipal dataset
 * (district/municipality codes) and independently-reported per-province
 * district/local counts — every province's district count and local-
 * municipality count below matches the publicly reported totals exactly
 * (44 districts, 205 locals, 8 metros nationally). A few source names had
 * encoding glitches or inconsistent formatting; those are normalised here
 * (e.g. "Khâi-Ma", "Madibeng", "Kagisano-Molopo").
 *
 * This is reference data for cascading dropdowns, not a legal register —
 * municipal demarcation does change over time, so treat this as "current
 * as of when it was compiled", not an authoritative feed.
 */
function sa_municipal_structure(): array
{
    return [
        'Eastern Cape' => [
            ['district' => 'Buffalo City', 'type' => 'metro', 'locals' => ['Buffalo City']],
            ['district' => 'Nelson Mandela Bay', 'type' => 'metro', 'locals' => ['Nelson Mandela Bay']],
            ['district' => 'Sarah Baartman District Municipality', 'type' => 'district', 'locals' => [
                'Dr Beyers Naudé', 'Blue Crane Route', 'Makana', 'Ndlambe', 'Sundays River Valley', 'Kouga', 'Kou-Kamma',
            ]],
            ['district' => 'Amathole District Municipality', 'type' => 'district', 'locals' => [
                'Mbhashe', 'Mnquma', 'Great Kei', 'Amahlathi', 'Ngqushwa', 'Raymond Mhlaba',
            ]],
            ['district' => 'Chris Hani District Municipality', 'type' => 'district', 'locals' => [
                'Inxuba Yethemba', 'Intsika Yethu', 'Emalahleni', 'Engcobo', 'Sakhisizwe', 'Enoch Mgijima',
            ]],
            ['district' => 'Joe Gqabi District Municipality', 'type' => 'district', 'locals' => [
                'Elundini', 'Senqu', 'Walter Sisulu',
            ]],
            ['district' => 'OR Tambo District Municipality', 'type' => 'district', 'locals' => [
                'Ngquza Hill', 'Port St Johns', 'Nyandeni', 'Mhlontlo', 'King Sabata Dalindyebo',
            ]],
            ['district' => 'Alfred Nzo District Municipality', 'type' => 'district', 'locals' => [
                'Matatiele', 'Umzimvubu', 'Winnie Madikizela-Mandela', 'Ntabankulu',
            ]],
        ],
        'Free State' => [
            ['district' => 'Mangaung', 'type' => 'metro', 'locals' => ['Mangaung']],
            ['district' => 'Xhariep District Municipality', 'type' => 'district', 'locals' => [
                'Letsemeng', 'Kopanong', 'Mohokare',
            ]],
            ['district' => 'Lejweleputswa District Municipality', 'type' => 'district', 'locals' => [
                'Masilonyana', 'Tokologo', 'Tswelopele', 'Matjhabeng', 'Nala',
            ]],
            ['district' => 'Thabo Mofutsanyana District Municipality', 'type' => 'district', 'locals' => [
                'Setsoto', 'Dihlabeng', 'Nketoana', 'Maluti a Phofung', 'Phumelela', 'Mantsopa',
            ]],
            ['district' => 'Fezile Dabi District Municipality', 'type' => 'district', 'locals' => [
                'Moqhaka', 'Ngwathe', 'Metsimaholo', 'Mafube',
            ]],
        ],
        'Gauteng' => [
            ['district' => 'Ekurhuleni', 'type' => 'metro', 'locals' => ['Ekurhuleni']],
            ['district' => 'City of Johannesburg', 'type' => 'metro', 'locals' => ['City of Johannesburg']],
            ['district' => 'City of Tshwane', 'type' => 'metro', 'locals' => ['City of Tshwane']],
            ['district' => 'Sedibeng District Municipality', 'type' => 'district', 'locals' => [
                'Emfuleni', 'Midvaal', 'Lesedi',
            ]],
            ['district' => 'West Rand District Municipality', 'type' => 'district', 'locals' => [
                'Mogale City', 'Merafong City', 'Rand West City',
            ]],
        ],
        'KwaZulu-Natal' => [
            ['district' => 'eThekwini', 'type' => 'metro', 'locals' => ['eThekwini']],
            ['district' => 'Ugu District Municipality', 'type' => 'district', 'locals' => [
                'Umdoni', 'Umzumbe', 'uMuziwabantu', 'Ray Nkonyeni',
            ]],
            ['district' => 'uMgungundlovu District Municipality', 'type' => 'district', 'locals' => [
                'uMshwathi', 'uMngeni', 'Mpofana', 'Impendle', 'The Msunduzi', 'Mkhambathini', 'Richmond',
            ]],
            ['district' => 'uThukela District Municipality', 'type' => 'district', 'locals' => [
                'Okhahlamba', 'Inkosi Langalibalele', 'Alfred Duma',
            ]],
            ['district' => 'uMzinyathi District Municipality', 'type' => 'district', 'locals' => [
                'Endumeni', 'Nqutu', 'Msinga', 'Umvoti',
            ]],
            ['district' => 'Amajuba District Municipality', 'type' => 'district', 'locals' => [
                'Newcastle', 'eMadlangeni', 'Dannhauser',
            ]],
            ['district' => 'Zululand District Municipality', 'type' => 'district', 'locals' => [
                'eDumbe', 'uPhongolo', 'Abaqulusi', 'Nongoma', 'Ulundi',
            ]],
            ['district' => 'uMkhanyakude District Municipality', 'type' => 'district', 'locals' => [
                'Umhlabuyalingana', 'Jozini', 'Mtubatuba', 'Big Five Hlabisa',
            ]],
            ['district' => 'King Cetshwayo District Municipality', 'type' => 'district', 'locals' => [
                'Mfolozi', 'uMhlathuze', 'uMlalazi', 'Mthonjaneni', 'Nkandla',
            ]],
            ['district' => 'iLembe District Municipality', 'type' => 'district', 'locals' => [
                'Mandeni', 'KwaDukuza', 'Ndwedwe', 'Maphumulo',
            ]],
            ['district' => 'Harry Gwala District Municipality', 'type' => 'district', 'locals' => [
                'Greater Kokstad', 'Ubuhlebezwe', 'Umzimkhulu', 'Dr Nkosazana Dlamini Zuma',
            ]],
        ],
        'Limpopo' => [
            ['district' => 'Mopani District Municipality', 'type' => 'district', 'locals' => [
                'Greater Giyani', 'Greater Letaba', 'Greater Tzaneen', 'Ba-Phalaborwa', 'Maruleng',
            ]],
            ['district' => 'Vhembe District Municipality', 'type' => 'district', 'locals' => [
                'Musina', 'Thulamela', 'Makhado', 'Collins Chabane',
            ]],
            ['district' => 'Capricorn District Municipality', 'type' => 'district', 'locals' => [
                'Blouberg', 'Molemole', 'Polokwane', 'Lepele-Nkumpi',
            ]],
            ['district' => 'Waterberg District Municipality', 'type' => 'district', 'locals' => [
                'Thabazimbi', 'Lephalale', 'Bela-Bela', 'Mogalakwena', 'Modimolle-Mookgophong',
            ]],
            ['district' => 'Sekhukhune District Municipality', 'type' => 'district', 'locals' => [
                'Ephraim Mogale', 'Elias Motsoaledi', 'Makhuduthamaga', 'Fetakgomo Tubatse',
            ]],
        ],
        'Mpumalanga' => [
            ['district' => 'Gert Sibande District Municipality', 'type' => 'district', 'locals' => [
                'Chief Albert Luthuli', 'Msukaligwa', 'Mkhondo', 'Dr Pixley Ka Isaka Seme', 'Lekwa', 'Dipaleseng', 'Govan Mbeki',
            ]],
            ['district' => 'Nkangala District Municipality', 'type' => 'district', 'locals' => [
                'Victor Khanye', 'Emalahleni', 'Steve Tshwete', 'Emakhazeni', 'Thembisile Hani', 'Dr JS Moroka',
            ]],
            ['district' => 'Ehlanzeni District Municipality', 'type' => 'district', 'locals' => [
                'Thaba Chweu', 'Nkomazi', 'Bushbuckridge', 'City of Mbombela',
            ]],
        ],
        'Northern Cape' => [
            ['district' => 'Namakwa District Municipality', 'type' => 'district', 'locals' => [
                'Richtersveld', 'Nama Khoi', 'Kamiesberg', 'Hantam', 'Karoo Hoogland', 'Khâi-Ma',
            ]],
            ['district' => 'Pixley ka Seme District Municipality', 'type' => 'district', 'locals' => [
                'Ubuntu', 'Umsobomvu', 'Emthanjeni', 'Kareeberg', 'Renosterberg', 'Thembelihle', 'Siyathemba', 'Siyancuma',
            ]],
            ['district' => 'ZF Mgcawu District Municipality', 'type' => 'district', 'locals' => [
                'Kai !Garib', '!Kheis', 'Tsantsabane', 'Kgatelopele', 'Dawid Kruiper',
            ]],
            ['district' => 'Frances Baard District Municipality', 'type' => 'district', 'locals' => [
                'Sol Plaatje', 'Dikgatlong', 'Magareng', 'Phokwane',
            ]],
            ['district' => 'John Taolo Gaetsewe District Municipality', 'type' => 'district', 'locals' => [
                'Joe Morolong', 'Ga-Segonyana', 'Gamagara',
            ]],
        ],
        'North West' => [
            ['district' => 'Bojanala Platinum District Municipality', 'type' => 'district', 'locals' => [
                'Moretele', 'Madibeng', 'Rustenburg', 'Kgetlengrivier', 'Moses Kotane',
            ]],
            ['district' => 'Ngaka Modiri Molema District Municipality', 'type' => 'district', 'locals' => [
                'Ratlou', 'Tswaing', 'Mahikeng', 'Ditsobotla', 'Ramotshere Moiloa',
            ]],
            ['district' => 'Dr Ruth Segomotsi Mompati District Municipality', 'type' => 'district', 'locals' => [
                'Naledi', 'Mamusa', 'Greater Taung', 'Lekwa-Teemane', 'Kagisano-Molopo',
            ]],
            ['district' => 'Dr Kenneth Kaunda District Municipality', 'type' => 'district', 'locals' => [
                'City of Matlosana', 'Maquassi Hills', 'JB Marks',
            ]],
        ],
        'Western Cape' => [
            ['district' => 'City of Cape Town', 'type' => 'metro', 'locals' => ['City of Cape Town']],
            ['district' => 'West Coast District Municipality', 'type' => 'district', 'locals' => [
                'Matzikama', 'Cederberg', 'Bergrivier', 'Saldanha Bay', 'Swartland',
            ]],
            ['district' => 'Cape Winelands District Municipality', 'type' => 'district', 'locals' => [
                'Witzenberg', 'Drakenstein', 'Stellenbosch', 'Breede Valley', 'Langeberg',
            ]],
            ['district' => 'Overberg District Municipality', 'type' => 'district', 'locals' => [
                'Theewaterskloof', 'Overstrand', 'Cape Agulhas', 'Swellendam',
            ]],
            ['district' => 'Garden Route District Municipality', 'type' => 'district', 'locals' => [
                'Kannaland', 'Hessequa', 'Mossel Bay', 'George', 'Oudtshoorn', 'Bitou', 'Knysna',
            ]],
            ['district' => 'Central Karoo District Municipality', 'type' => 'district', 'locals' => [
                'Laingsburg', 'Prince Albert', 'Beaufort West',
            ]],
        ],
    ];
}
