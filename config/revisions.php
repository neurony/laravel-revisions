<?php

return [

    /*
    |
    | The full namespace to your User model class.
    |
    | If your application doesn't have a user class, the value should be "NULL".
    |
    */
    'user_model' => '\App\User',

    /*
    |
    | Concrete implementation for the "revision model".
    | To extend or replace this functionality, change the value below with your full "revision model" FQN.
    |
    | Your class will have to (first option is recommended):
    | - extend the "Neurony\Revisions\Models\Revision" class
    | - or at least implement the "Neurony\Revisions\Contracts\RevisionModelContract" interface.
    |
    | Regardless of the concrete implementation below, you can still use it like:
    | - app('revision.model') OR app('\Neurony\Revisions\Contracts\RevisionModelContract')
    | - or you could even use your own class as a direct implementation
    |
    */
    'revision_model' => \Neurony\Revisions\Models\Revision::class,

];
