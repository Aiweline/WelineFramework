<?php
declare(strict_types=1);
namespace Weline\I18n\Test\Unit\Service;
use PHPUnit\Framework\TestCase;
final class CountryLocaleLifecycleIdempotenceTest extends TestCase
{
 public function testCompleteActivationDoesNotWriteOrInvalidate(): void
 {
  foreach (['activateLocale','activateCountry'] as $method) foreach (['complete','string-flags'] as $scenario) {
   $result=$this->runFixture($scenario,$method);
   foreach ($result['results'] as $pass) $this->assertNoMutation($pass);
  }
 }
 public function testActivationRepairsIncompleteDirectoriesThenBecomesIdempotent(): void
 {
  foreach (['activateLocale','activateCountry'] as $method) foreach (['missing-locals','stale-mirror','missing-country-flag','missing-iso','wrong-country','missing-country-name','missing-locale-name','blank-country-name','blank-locale-name','inactive'] as $scenario) {
   $result=$this->runFixture($scenario,$method);
   $first=$result['results'][0]; self::assertNull($first['error'],$scenario.' '.$method);
   self::assertNotEmpty($first['writes'],$scenario.' '.$method);
   self::assertSame([['action'=>'locale-catalog-changed','locale'=>null]],$first['calls'],$scenario.' '.$method);
   self::assertSame(1,$first['committed']);
   self::assertSame('US',$result['locale'][0]['country_code']);
   self::assertSame('ENG',$result['locale'][0]['iso3']);
   self::assertSame('flag',$result['country'][0]['flag']);
   self::assertCount(3,$result['country_names']); self::assertCount(3,$result['locale_names']);
   foreach ($result['country_names'] as $name) self::assertSame('United States',$name['display_name']);
   foreach ($result['locale_names'] as $name) self::assertSame('English',$name['display_name']);
   self::assertNotEmpty($result['locals']);
   foreach($result['locals'] as $mirror) {self::assertSame(1,(int)$mirror['is_install']);self::assertSame(1,(int)$mirror['is_active']);}
   $this->assertNoMutation($result['results'][1]);
  }
 }
 public function testFailedTransactionsDoNotCommitRepairsOrInvalidation(): void
 {
  foreach(['rollback','changed-failure'] as $scenario) {
   $result=$this->runFixture($scenario,'activateLocale');
   foreach($result['results'] as $pass) {self::assertNotNull($pass['error']);self::assertSame(0,$pass['committed']);self::assertSame([],$pass['writes']);}
   self::assertSame([],$result['locals']);
  }
 }
 private function assertNoMutation(array $pass):void
 {
  self::assertNull($pass['error']); self::assertSame([],$pass['writes']);self::assertSame([],$pass['calls']);self::assertSame(0,$pass['committed']);
  self::assertSame(1,$pass['result']['is_install']);self::assertSame(1,$pass['result']['is_active']);
 }
 private function runFixture(string $scenario,string $method):array
 {
  $fixture=dirname(__DIR__, 2) . '/fixtures/country-locale-lifecycle.php';
  $output=[];$status=0;
  exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture).' '.escapeshellarg($scenario).' '.escapeshellarg($method).' 2>&1',$output,$status);
  self::assertSame(0,$status,implode("\n",$output));
  $result=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
  self::assertFalse($result['transaction_open']);return $result;
 }
}
