<?php
namespace Etr;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Demo mode player pool: 50 real chess players spanning eras and genders, used
 * to generate dummy registrants for exercising the Registrations tab without
 * needing real ticket sales. Ratings are plausible approximations - peak
 * (or current, for active players) ratings where an Elo-era rating exists,
 * rough historical estimates for players who predate FIDE ratings. USCF IDs
 * are clearly test IDs, in the 90000001-90000050 range, assigned by list
 * position so the same player always gets the same test ID.
 *
 * @return array<int, array{last:string,first:string,rating:int,uscf_id:string}>
 */

$players = [
	[ 'last' => 'Carlsen',        'first' => 'Magnus',            'rating' => 2830 ],
	[ 'last' => 'Kasparov',       'first' => 'Garry',              'rating' => 2851 ],
	[ 'last' => 'Fischer',        'first' => 'Robert',              'rating' => 2785 ],
	[ 'last' => 'Morphy',         'first' => 'Paul',                'rating' => 2690 ],
	[ 'last' => 'Menchik',        'first' => 'Vera',                'rating' => 2350 ],
	[ 'last' => 'Polgar',         'first' => 'Judit',                'rating' => 2735 ],
	[ 'last' => 'Hou',            'first' => 'Yifan',              'rating' => 2650 ],
	[ 'last' => 'Gaprindashvili', 'first' => 'Nona',        'rating' => 2495 ],
	[ 'last' => 'Chiburdanidze',  'first' => 'Maia',          'rating' => 2560 ],
	[ 'last' => 'Krush',          'first' => 'Irina',                'rating' => 2477 ],
	[ 'last' => 'Karpov',         'first' => 'Anatoly',              'rating' => 2780 ],
	[ 'last' => 'Anand',          'first' => 'Viswanathan',           'rating' => 2817 ],
	[ 'last' => 'Kramnik',        'first' => 'Vladimir',            'rating' => 2817 ],
	[ 'last' => 'Lasker',         'first' => 'Emanuel',              'rating' => 2720 ],
	[ 'last' => 'Capablanca',     'first' => 'Jose Raul',          'rating' => 2725 ],
	[ 'last' => 'Alekhine',       'first' => 'Alexander',            'rating' => 2740 ],
	[ 'last' => 'Tal',            'first' => 'Mikhail',                 'rating' => 2700 ],
	[ 'last' => 'Botvinnik',      'first' => 'Mikhail',            'rating' => 2730 ],
	[ 'last' => 'Spassky',        'first' => 'Boris',              'rating' => 2690 ],
	[ 'last' => 'Petrosian',      'first' => 'Tigran',            'rating' => 2645 ],
	[ 'last' => 'Steinitz',       'first' => 'Wilhelm',             'rating' => 2650 ],
	[ 'last' => 'Euwe',           'first' => 'Max',                    'rating' => 2620 ],
	[ 'last' => 'Ivanchuk',       'first' => 'Vasyl',              'rating' => 2740 ],
	[ 'last' => 'Aronian',        'first' => 'Levon',                'rating' => 2830 ],
	[ 'last' => 'Caruana',        'first' => 'Fabiano',              'rating' => 2844 ],
	[ 'last' => 'Nakamura',       'first' => 'Hikaru',              'rating' => 2802 ],
	[ 'last' => 'So',             'first' => 'Wesley',                  'rating' => 2780 ],
	[ 'last' => 'Ding',           'first' => 'Liren',                  'rating' => 2816 ],
	[ 'last' => 'Nepomniachtchi', 'first' => 'Ian',           'rating' => 2792 ],
	[ 'last' => 'Firouzja',       'first' => 'Alireza',             'rating' => 2804 ],
	[ 'last' => 'Grischuk',       'first' => 'Alexander',            'rating' => 2777 ],
	[ 'last' => 'Svidler',        'first' => 'Peter',                'rating' => 2754 ],
	[ 'last' => 'Topalov',        'first' => 'Veselin',              'rating' => 2813 ],
	[ 'last' => 'Adams',          'first' => 'Michael',                'rating' => 2740 ],
	[ 'last' => 'Polgar',         'first' => 'Susan',                'rating' => 2577 ],
	[ 'last' => 'Polgar',         'first' => 'Sofia',                'rating' => 2505 ],
	[ 'last' => 'Zhu',            'first' => 'Chen',                  'rating' => 2503 ],
	[ 'last' => 'Xie',            'first' => 'Jun',                    'rating' => 2536 ],
	[ 'last' => 'Stefanova',      'first' => 'Antoaneta',          'rating' => 2560 ],
	[ 'last' => 'Kosteniuk',      'first' => 'Alexandra',          'rating' => 2525 ],
	[ 'last' => 'Koneru',         'first' => 'Humpy',                'rating' => 2600 ],
	[ 'last' => 'Lagno',          'first' => 'Kateryna',              'rating' => 2560 ],
	[ 'last' => 'Ju',             'first' => 'Wenjun',                  'rating' => 2584 ],
	[ 'last' => 'Muzychuk',       'first' => 'Mariya',              'rating' => 2545 ],
	[ 'last' => 'Muzychuk',       'first' => 'Anna',                'rating' => 2565 ],
	[ 'last' => 'Cramling',       'first' => 'Pia',                  'rating' => 2540 ],
	[ 'last' => 'Paehtz',         'first' => 'Elisabeth',            'rating' => 2477 ],
	[ 'last' => 'Rubinstein',     'first' => 'Akiba',            'rating' => 2700 ],
	[ 'last' => 'Keres',          'first' => 'Paul',                  'rating' => 2700 ],
	[ 'last' => 'Bronstein',      'first' => 'David',              'rating' => 2650 ],
];

$out = [];
foreach ( $players as $i => $p ) {
	$p['uscf_id'] = (string) ( 90000001 + $i );
	$out[]        = $p;
}
return $out;
