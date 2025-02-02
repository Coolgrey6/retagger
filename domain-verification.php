<?php
namespace humhub\modules\domainverification;

use Yii;
use yii\helpers\Url;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;

class Module extends \humhub\components\Module
{
    const VERIFICATION_DNS = 'dns';
    const VERIFICATION_META = 'meta';
    
    public $resourcesPath = 'resources';

    public function init()
    {
        parent::init();
    }

    public function generateVerificationToken($domain, $userId)
    {
        return hash('sha256', $domain . $userId . Yii::$app->params['secret']);
    }
}

// models/DomainVerification.php
namespace humhub\modules\domainverification\models;

use Yii;
use yii\db\ActiveRecord;
use humhub\modules\space\models\Space;
use humhub\modules\user\models\User;

class DomainVerification extends ActiveRecord
{
    public static function tableName()
    {
        return 'domain_verification';
    }

    public function rules()
    {
        return [
            [['domain', 'user_id', 'verification_token', 'verification_method'], 'required'],
            [['user_id', 'space_id', 'verified'], 'integer'],
            [['domain', 'verification_token', 'verification_method'], 'string'],
            [['verified_at'], 'safe'],
        ];
    }

    public function getSpace()
    {
        return $this->hasOne(Space::class, ['id' => 'space_id']);
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}

// controllers/VerificationController.php
namespace humhub\modules\domainverification\controllers;

use Yii;
use yii\web\Controller;
use humhub\modules\domainverification\models\DomainVerification;
use humhub\modules\space\models\Space;

class VerificationController extends Controller
{
    public function actionVerify($domain)
    {
        $model = DomainVerification::findOne([
            'domain' => $domain,
            'user_id' => Yii::$app->user->id,
            'verified' => 0
        ]);

        if (!$model) {
            throw new NotFoundHttpException('Verification not found.');
        }

        $verified = false;
        if ($model->verification_method === Module::VERIFICATION_DNS) {
            $verified = $this->verifyDNS($domain, $model->verification_token);
        } else if ($model->verification_method === Module::VERIFICATION_META) {
            $verified = $this->verifyMetaTag($domain, $model->verification_token);
        }

        if ($verified) {
            $model->verified = 1;
            $model->verified_at = date('Y-m-d H:i:s');
            $model->save();

            // Create or update space permissions
            $space = $model->space;
            if ($space) {
                $space->addMember($model->user_id, Space::USERGROUP_ADMIN);
                Yii::$app->session->setFlash('success', 'Domain verified and admin rights granted!');
            }
        } else {
            Yii::$app->session->setFlash('error', 'Domain verification failed. Please check your settings.');
        }

        return $this->redirect(['index']);
    }

    private function verifyDNS($domain, $token)
    {
        $records = dns_get_record($domain, DNS_TXT);
        foreach ($records as $record) {
            if (isset($record['txt']) && $record['txt'] === "humhub-verify={$token}") {
                return true;
            }
        }
        return false;
    }

    private function verifyMetaTag($domain, $token)
    {
        try {
            $url = "https://" . $domain;
            $html = file_get_contents($url);
            return strpos($html, "<meta name=\"humhub-verify\" content=\"{$token}\">") !== false;
        } catch (\Exception $e) {
            Yii::error("Meta tag verification failed: " . $e->getMessage());
            return false;
        }
    }
}

// migrations/m240201_000000_create_domain_verification.php
namespace humhub\modules\domainverification\migrations;

use yii\db\Migration;

class m240201_000000_create_domain_verification extends Migration
{
    public function up()
    {
        $this->createTable('domain_verification', [
            'id' => $this->primaryKey(),
            'domain' => $this->string()->notNull(),
            'user_id' => $this->integer()->notNull(),
            'space_id' => $this->integer(),
            'verification_token' => $this->string()->notNull(),
            'verification_method' => $this->string()->notNull(),
            'verified' => $this->boolean()->defaultValue(0),
            'verified_at' => $this->dateTime(),
            'created_at' => $this->dateTime()->notNull(),
            'updated_at' => $this->dateTime()->notNull(),
        ]);

        $this->addForeignKey(
            'fk_domain_verification_user',
            'domain_verification',
            'user_id',
            'user',
            'id',
            'CASCADE'
        );

        $this->addForeignKey(
            'fk_domain_verification_space',
            'domain_verification',
            'space_id',
            'space',
            'id',
            'CASCADE'
        );
    }

    public function down()
    {
        $this->dropTable('domain_verification');
    }
}