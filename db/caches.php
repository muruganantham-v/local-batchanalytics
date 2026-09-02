<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'batchdata' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 300, // 5 minutes
    ],
    'zohotokendata' => [
        // Dedicated store for the Zoho OAuth access token.
        // No TTL — crmapi.php already validates the expires field itself;
        // MUC must not evict the entry before the application-level check runs.
        // simpledata => false because the stored value is an array
        // (['token' => …, 'expires' => …]), not a scalar. (F-18)
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
    ],
    'crmratelimit' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        // The counter keeps its fixed window start time alongside the request count. (F-21)
        'simpledata' => false,
        'ttl' => 60, // 1 minute window for rate limiting
    ]
];
