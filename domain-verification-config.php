<?php
// config.php
return [
    'id' => 'domainverification',
    'class' => 'humhub\modules\domainverification\Module',
    'namespace' => 'humhub\modules\domainverification',
    'events' => [
        [
            'class' => \humhub\modules\highlights\models\Highlight::class,
            'event' => \humhub\modules\highlights\models\Highlight::EVENT_BEFORE_INSERT,
            'callback' => ['humhub\modules\domainverification\Events', 'onHighlightBeforeInsert']
        ],
    ]
];

// Events.php
namespace humhub\modules\domainverification;

use Yii;
use humhub\modules\highlights\models\Highlight;
use humhub\modules\domainverification\models\DomainVerification;

class Events
{
    public static function onHighlightBeforeInsert($event)
    {
        /** @var Highlight $highlight */
        $highlight = $event->sender;
        
        // Extract domain from URL
        $domain = parse_url($highlight->url, PHP_URL_HOST);
        
        if ($domain) {
            // Check if domain is already verified
            $verification = DomainVerification::findOne([
                'domain' => $domain,
                'verified' => 1
            ]);
            
            if ($verification) {
                // If domain is verified, set the space_id to the verified domain's space
                $highlight->space_id = $verification->space_id;
            } else {
                // Create new verification request
                $module = Yii::$app->getModule('domainverification');
                $token = $module->generateVerificationToken($domain, Yii::$app->user->id);
                
                $verification = new DomainVerification([
                    'domain' => $domain,
                    'user_id' => Yii::$app->user->id,
                    'verification_token' => $token,
                    'verification_method' => Module::VERIFICATION_DNS,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
                
                $verification->save();
            }
        }
    }
}