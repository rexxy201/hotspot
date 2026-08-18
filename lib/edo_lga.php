<?php
/**
 * The 18 Local Government Areas of Edo State, Nigeria.
 *
 * Used to populate the sign-up form's LGA dropdown (index.php) AND to
 * validate the submitted value server-side (connect.php) against this
 * same fixed list — the raffle draw and admin reporting both depend on
 * this being a consistent, known set of values, not arbitrary free text
 * an attendee could type any way they like ("Oredo", "oredo LGA", "Oredo,
 * Benin City" would all otherwise land as different values).
 */
const EDO_LGAS = [
    'Akoko-Edo',
    'Egor',
    'Esan Central',
    'Esan North-East',
    'Esan South-East',
    'Esan West',
    'Etsako Central',
    'Etsako East',
    'Etsako West',
    'Igueben',
    'Ikpoba-Okha',
    'Orhionmwon',
    'Oredo',
    'Ovia North-East',
    'Ovia South-West',
    'Owan East',
    'Owan West',
    'Uhunmwonde',
];
