<?php
declare(strict_types=1);
// Real models/SQLite transactions; only external catalog and changed broadcaster are doubles.
require __DIR__ . '/dictionary-write-transaction.php';
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Transaction\TransactionCoordinator;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Database\TransactionContext;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\I18n\Model\{Countries,Locale,Locals,I18n};
use Weline\I18n\Model\Countries\Locale\Name as CountryName;
use Weline\I18n\Model\Locale\Name as LocaleName;
use Weline\I18n\Service\{CountryLocaleLifecycleService,I18nResourceChangePublisher};
final class CountryLifecycleFixture extends \PHPUnit\Framework\TestCase
{
 public function runScenario(string $scenario, string $method): array
 {
  $file=tempnam(sys_get_temp_dir(),'weline-country-lifecycle-');
  $config=new ConfigProvider(['type'=>'sqlite','database'=>'','path'=>$file,'persistent'=>false]);
  $connector=new Connector($config);
  $connection=$this->createMock(ConnectionFactory::class);
  $connection->method('getConnector')->willReturn($connector);
  $connection->method('getConfigProvider')->willReturn($config);
  $transactions=new TransactionCoordinator();
  $publisher=new I18nResourceChangePublisher($connection,$transactions);
  ObjectManager::setInstance(TransactionCoordinatorInterface::class,$transactions);
  ObjectManager::setInstance(I18nResourceChangePublisher::class,$publisher);
  RequestContext::init(); TransactionContext::reset();
  try {
   foreach ([Countries::class=>['code','code TEXT PRIMARY KEY, is_install INTEGER, is_active INTEGER, flag TEXT'],Locale::class=>['code','code TEXT PRIMARY KEY, country_code TEXT, short_code TEXT, iso2 TEXT, iso3 TEXT, is_install INTEGER, is_active INTEGER, flag TEXT'],CountryName::class=>['country_code','country_code TEXT, display_locale_code TEXT, display_name TEXT, PRIMARY KEY(country_code,display_locale_code)'],LocaleName::class=>['locale_code','locale_code TEXT, display_locale_code TEXT, display_name TEXT, PRIMARY KEY(locale_code,display_locale_code)'],Locals::class=>['code','code TEXT, target_code TEXT, name TEXT, is_install INTEGER, is_active INTEGER, flag TEXT, PRIMARY KEY(code,target_code)']] as $class=>[$pk,$schema]) {
    if ($scenario === 'string-flags') $schema = str_replace('INTEGER', 'TEXT', $schema);
    $connector->query('CREATE TABLE '.$class::schema_table.' ('.$schema.')')->fetch();
    $model=(new ReflectionClass($class))->newInstanceWithoutConstructor();
    $model->setConnection($connection); $model->_primary_key=$pk; $model->origin_table_name=$class::schema_table;
    ObjectManager::setInstance($class,$model);
   }
   $connector->query('CREATE TABLE probe_changes (marker TEXT NOT NULL)')->fetch();
   $connector->query('CREATE TABLE probe_writes (table_name TEXT)')->fetch();
   $connector->query("INSERT INTO i18n_countries VALUES ('US',1,1,'flag')")->fetch();
   $connector->query("INSERT INTO i18n_locale VALUES ('en_US','US','EN','EN','ENG',1,1,'flag')")->fetch();
   foreach(['en_US','en','zh_Hans_CN'] as $display) {
    $connector->query("INSERT INTO i18n_countries_locale_name VALUES ('US','$display','United States')")->fetch();
    $connector->query("INSERT INTO i18n_locale_name VALUES ('en_US','$display','English')")->fetch();
   }
   $connector->query("INSERT INTO i18n_locals VALUES ('en_US','en_US','English',1,1,'flag'),('en_US','zh_Hans_CN','英语',1,1,'flag')")->fetch();
   $i18n=$this->getMockBuilder(I18n::class)->disableOriginalConstructor()->onlyMethods(['getCountry','getCountryFlag','getCountryFlagWithLocal','getCountries','getLocaleName','localeExists'])->getMock();
   $i18n->method('getCountry')->willReturn(['locales'=>['en_US']]);
   $i18n->method('getCountryFlag')->willReturn('flag');
   $i18n->method('getCountryFlagWithLocal')->willReturn(['flag'=>'flag']);
   $i18n->method('getCountries')->willReturn(['US'=>'United States']);
   $i18n->method('getLocaleName')->willReturn('English');
   $i18n->method('localeExists')->willReturn(true);
   ObjectManager::setInstance(I18n::class,$i18n);
   $patches=[
    'missing-locals'=>"DELETE FROM i18n_locals",
    'stale-mirror'=>"UPDATE i18n_locals SET is_active=0 WHERE target_code='zh_Hans_CN'",
    'missing-country-flag'=>"UPDATE i18n_countries SET flag=''",
    'missing-iso'=>"UPDATE i18n_locale SET iso3=''",
    'wrong-country'=>"UPDATE i18n_locale SET country_code='ZZ'",
    'missing-country-name'=>"DELETE FROM i18n_countries_locale_name WHERE display_locale_code='en'",
    'missing-locale-name'=>"DELETE FROM i18n_locale_name WHERE display_locale_code='en'",
    'blank-country-name'=>"UPDATE i18n_countries_locale_name SET display_name='' WHERE display_locale_code='en'",
    'blank-locale-name'=>"UPDATE i18n_locale_name SET display_name='' WHERE display_locale_code='en'",
    'inactive'=>"UPDATE i18n_locale SET is_install=0,is_active=0",
    'rollback'=>"DELETE FROM i18n_locals",
    'changed-failure'=>"DELETE FROM i18n_locals",
   ];
   if(isset($patches[$scenario])) $connector->query($patches[$scenario])->fetch();
   foreach(['i18n_countries','i18n_locale','i18n_countries_locale_name','i18n_locale_name','i18n_locals'] as $table) foreach(['INSERT','UPDATE','DELETE'] as $event) (new PDO('sqlite:'.$file))->exec("CREATE TRIGGER audit_{$table}_{$event} AFTER $event ON $table BEGIN INSERT INTO probe_writes VALUES ('$table'); END");
   $publisher->fail=$scenario==='changed-failure';
   $service=new CountryLocaleLifecycleService(); $results=[];
   for($i=0;$i<2;$i++) {
    $connector->query('DELETE FROM probe_writes')->fetch(); $publisher->calls=[];  $publisher->committed=0; $error=null; $value=null;
    try { if($scenario==='rollback') $transactions->run($connection,function()use($service,$method){$service->$method($method==='activateCountry'?'US':'en_US');throw new RuntimeException('outer_rollback');}); else $value=$service->$method($method==='activateCountry'?'US':'en_US'); } catch(Throwable $e){$error=$e->getMessage();}
    $results[]=['error'=>$error,'result'=>$value,'writes'=>$connector->query('SELECT * FROM probe_writes')->fetch(),'calls'=>$publisher->calls,'committed'=>$publisher->committed];
   }
   return ['results'=>$results,'country'=>$connector->query('SELECT * FROM i18n_countries')->fetch(),'locale'=>$connector->query('SELECT * FROM i18n_locale')->fetch(),'locals'=>$connector->query('SELECT * FROM i18n_locals ORDER BY target_code')->fetch(),'country_names'=>$connector->query('SELECT * FROM i18n_countries_locale_name')->fetch(),'locale_names'=>$connector->query('SELECT * FROM i18n_locale_name')->fetch(),'transaction_open'=>$connector->getWrappedConnection()->inTransaction()];
  } finally {TransactionContext::reset();RequestContext::cleanup();$connector->close();unlink($file);}
 }
}
echo json_encode((new CountryLifecycleFixture('runScenario'))->runScenario($argv[1]??'complete',$argv[2]??'activateLocale'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),PHP_EOL;
