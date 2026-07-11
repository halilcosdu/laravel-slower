<?php

arch('the package ships no leftover debugging statements')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'var_export'])
    ->not->toBeUsed();

arch('the package is free of PHP smells')
    ->preset()
    ->php();
