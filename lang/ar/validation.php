<?php

/*
 * رسائل التحقق بالعربية.
 *
 * Pendant du fichier français : mêmes règles, mêmes clés. Une règle absente
 * retombe sur le message anglais du cadriciel plutôt que sur une clé brute.
 */

return [
    'accepted' => 'يجب قبول :attribute.',
    'after' => 'يجب أن يكون :attribute تاريخا بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخا بعد :date أو مساويا له.',
    'array' => 'يجب أن يكون :attribute لائحة.',
    'before' => 'يجب أن يكون :attribute تاريخا قبل :date.',
    'boolean' => 'يجب أن يكون :attribute صحيحا أو خاطئا.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'date' => ':attribute ليس تاريخا صحيحا.',
    'date_format' => ':attribute لا يطابق الصيغة :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal أرقام عشرية.',
    'different' => 'يجب أن يكون :attribute مختلفا عن :other.',
    'digits' => 'يجب أن يتكون :attribute من :digits أرقام.',
    'distinct' => 'يحتوي :attribute على قيمة مكررة.',
    'email' => 'يجب أن يكون :attribute عنوان بريد إلكتروني صحيحا.',
    'exists' => 'القيمة المختارة لـ :attribute غير صالحة.',
    'file' => 'يجب أن يكون :attribute ملفا.',
    'gt' => [
        'array' => 'يجب أن يحتوي :attribute على أكثر من :value عنصرا.',
        'file' => 'يجب أن يتجاوز حجم :attribute :value كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute أكبر من :value.',
        'string' => 'يجب أن يتجاوز :attribute :value حرفا.',
    ],
    'gte' => [
        'array' => 'يجب أن يحتوي :attribute على :value عنصرا على الأقل.',
        'file' => 'يجب ألا يقل حجم :attribute عن :value كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute أكبر من :value أو مساويا له.',
        'string' => 'يجب ألا يقل :attribute عن :value حرفا.',
    ],
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => 'القيمة المختارة لـ :attribute غير صالحة.',
    'integer' => 'يجب أن يكون :attribute عددا صحيحا.',
    'lt' => [
        'array' => 'يجب أن يحتوي :attribute على أقل من :value عنصرا.',
        'file' => 'يجب أن يقل حجم :attribute عن :value كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute أصغر من :value.',
        'string' => 'يجب أن يقل :attribute عن :value حرفا.',
    ],
    'lte' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :value عنصرا.',
        'file' => 'يجب ألا يتجاوز حجم :attribute :value كيلوبايت.',
        'numeric' => 'يجب أن يكون :attribute أصغر من :value أو مساويا له.',
        'string' => 'يجب ألا يتجاوز :attribute :value حرفا.',
    ],
    'max' => [
        'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عنصرا.',
        'file' => 'يجب ألا يتجاوز حجم :attribute :max كيلوبايت.',
        'numeric' => 'يجب ألا يتجاوز :attribute :max.',
        'string' => 'يجب ألا يتجاوز :attribute :max حرفا.',
    ],
    'mimes' => 'يجب أن يكون :attribute ملفا من نوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي :attribute على :min عنصرا على الأقل.',
        'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا يقل :attribute عن :min.',
        'string' => 'يجب ألا يقل :attribute عن :min حرفا.',
    ],
    'numeric' => 'يجب أن يكون :attribute رقما.',
    'prohibited' => ':attribute غير مسموح به.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => ':attribute مطلوب.',
    'required_if' => ':attribute مطلوب عندما يكون :other مساويا لـ :value.',
    'required_with' => ':attribute مطلوب عند تعبئة :values.',
    'required_without' => ':attribute مطلوب عندما لا يكون :values معبأ.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',
    'size' => [
        'array' => 'يجب أن يحتوي :attribute على :size عنصرا.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن يساوي :attribute :size.',
        'string' => 'يجب أن يتكون :attribute من :size حرفا.',
    ],
    'string' => 'يجب أن يكون :attribute نصا.',
    'unique' => 'قيمة :attribute مستعملة من قبل.',
    'url' => 'يجب أن يكون :attribute رابطا صحيحا.',

    'custom' => [],

    'attributes' => [],
];
