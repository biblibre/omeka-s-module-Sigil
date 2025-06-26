<?php

namespace Sigil\Form;

use Laminas\Form\Form;
use Laminas\InputFilter\InputFilterProviderInterface;

class ConfigForm extends Form implements InputFilterProviderInterface
{
    public function init()
    {
        $this->add([
            'name' => 'sigil_asset_id',
            'type' => 'Omeka\Form\Element\Asset',
            'options' => [
                'label' => 'Watermark file', // @translate
                'info' => 'Image file to use as watermark', // @translate
            ],
        ]);

        $this->add([
            'name' => 'sigil_gravity',
            'type' => 'Laminas\Form\Element\Select',
            'options' => [
                'label' => 'Position', // @translate
                'empty_option' => '',
                'value_options' => [
                    'Center' => 'Center', // @translate
                    'East' => 'East', // @translate
                    'NorthEast' => 'North East', // @translate
                    'North' => 'North', // @translate
                    'NorthWest' => 'North West', // @translate
                    'SouthEast' => 'South East', // @translate
                    'South' => 'South', // @translate
                    'SouthWest' => 'South West', // @translate
                    'West' => 'West', // @translate
                ],
            ],
        ]);
    }

    public function getInputFilterSpecification()
    {
        return [
            [
                'name' => 'sigil_gravity',
                'required' => false,
            ]
        ];
    }
}
