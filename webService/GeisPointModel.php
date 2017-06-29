<?php
/*
* (c) 2016 Geis CZ s.r.o.
*/

class GeisPointModel
{
    public function __construct($detail) {
        $this->idGP = $detail->idGP;
        $this->idRegion = $detail->idRegion;
        $this->name = $detail->name;
        $this->city = $detail->city;
        $this->street = $detail->street;
        $this->postcode = $detail->postcode;
        $this->country = $detail->country;
        $this->email = $detail->email;
        $this->phone = $detail->phone;
        $this->openiningHours = $detail->openiningHours;
        $this->holiday = $detail->holiday;
        $this->mapUrl = $detail->mapUrl;
        $this->gpsn = $detail->gpsn;
        $this->gpse = $detail->gpse;
        $this->photoUrl = $detail->photoUrl;
        $this->note = $detail->note;
    }

    public $idGP;
    public $idRegion;
    public $name;
    public $city;
    public $street;
    public $postcode;
    public $country;
    public $email;
    public $phone;
    public $openiningHours;
    public $holiday;
    public $mapUrl;
    public $gpsn;
    public $gpse;
    public $photoUrl;
    public $note;
}