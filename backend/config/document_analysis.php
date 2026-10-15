<?php

return [
    'supported_types' => [
        'birth_certificate' => [
            'label' => 'Birth Certificate',
            'aliases' => [
                'Birth Certificate',
                'Certificate of Live Birth',
                'PSA Birth Certificate',
                'PSA Certificate of Live Birth',
            ],
            'extraction_fields' => [
                'student_full_name' => [
                    'type' => 'STRING',
                    'description' => 'Full name of the person whose birth is recorded.',
                ],
                'date_of_birth' => [
                    'type' => 'STRING',
                    'description' => 'Birth date normalized to YYYY-MM-DD when legible.',
                ],
                'place_of_birth' => [
                    'type' => 'STRING',
                    'description' => 'Place of birth when legible.',
                ],
            ],
        ],
        'certificate_of_enrollment' => [
            'label' => 'Certificate of Enrollment',
            'aliases' => [
                'Certificate of Enrollment',
                'Enrollment Certificate',
                'Enrollment Slip',
                'Proof of Enrollment',
            ],
            'extraction_fields' => [
                'student_full_name' => [
                    'type' => 'STRING',
                    'description' => 'Full student name printed on the enrollment document.',
                ],
                'student_number' => [
                    'type' => 'STRING',
                    'description' => 'Student number or student ID when visible.',
                ],
                'course_program' => [
                    'type' => 'STRING',
                    'description' => 'Course or academic program when visible.',
                ],
                'year_level' => [
                    'type' => 'STRING',
                    'description' => 'Student year level when visible.',
                ],
                'academic_year' => [
                    'type' => 'STRING',
                    'description' => 'Academic or school year when visible.',
                ],
                'semester' => [
                    'type' => 'STRING',
                    'description' => 'Semester or term when visible.',
                ],
                'institution' => [
                    'type' => 'STRING',
                    'description' => 'Issuing school or institution.',
                ],
            ],
        ],
        'form_137' => [
            'label' => 'Form 137',
            'aliases' => [
                'Form 137',
                'Form 137-E',
                'Learner Permanent Record',
                'Permanent School Record',
                'School Form 10',
            ],
            'extraction_fields' => [
                'student_full_name' => [
                    'type' => 'STRING',
                    'description' => 'Full learner name printed on the school record.',
                ],
                'school_name' => [
                    'type' => 'STRING',
                    'description' => 'School name printed on the record.',
                ],
                'grade_year_information' => [
                    'type' => 'STRING',
                    'description' => 'Grade or year-level information visible on the record.',
                ],
                'subjects_grades_present' => [
                    'type' => 'BOOLEAN',
                    'description' => 'Whether the document visibly contains subjects or learning areas with grades or ratings.',
                ],
                'school_year' => [
                    'type' => 'STRING',
                    'description' => 'School year when visible.',
                ],
            ],
        ],
        'good_moral' => [
            'label' => 'Good Moral Certificate',
            'aliases' => [
                'Good Moral Certificate',
                'Certificate of Good Moral Character',
                'Good Moral Character Certificate',
            ],
            'extraction_fields' => [
                'student_full_name' => [
                    'type' => 'STRING',
                    'description' => 'Full student name printed on the certificate.',
                ],
                'issuing_school' => [
                    'type' => 'STRING',
                    'description' => 'School or institution issuing the certificate.',
                ],
                'date_issued' => [
                    'type' => 'STRING',
                    'description' => 'Issue date normalized to YYYY-MM-DD when legible.',
                ],
                'signatory_name' => [
                    'type' => 'STRING',
                    'description' => 'Printed signatory name when visible.',
                ],
                'signatory_position' => [
                    'type' => 'STRING',
                    'description' => 'Printed signatory position or title when visible.',
                ],
            ],
        ],
    ],
];
