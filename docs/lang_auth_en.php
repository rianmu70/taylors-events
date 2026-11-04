<?php

// Merge these into app/Views/lang/en.php. I wrote them as flat dotted keys; if en.php is nested, convert.
return [
    'auth.login.title'            => 'Sign in',
    'auth.login.submit'           => 'Sign in',
    'auth.login.create_account'   => 'Create an account',
    'auth.register.title'         => 'Create account',
    'auth.register.submit'        => 'Create account',
    'auth.register.have_account'  => 'I already have an account',
    'auth.logout'                 => 'Sign out',

    'auth.field.name'             => 'Full name',
    'auth.field.email'            => 'Email',
    'auth.field.password'         => 'Password',
    'auth.field.password_hint'    => '(10 to 128 characters)',
    'auth.field.faculty'          => 'Faculty',
    'auth.field.faculty_choose'   => 'Select',

    'auth.error.login_failed'     => 'Email or password is incorrect, or there were too many attempts. Try again later.',
    'auth.error.name'             => 'Enter your full name (2 to 100 characters).',
    'auth.error.email'            => 'Enter a valid email address.',
    'auth.error.email_taken'      => 'That email is already registered.',
    'auth.error.password_length'  => 'Password must be 10 to 128 characters.',
    'auth.error.password_is_email'=> 'Password cannot be your email address.',
    'auth.error.faculty'          => 'Choose a valid faculty.',
    'auth.error.signup_limited'   => 'Too many sign-ups from this network. Try again later.',
];
