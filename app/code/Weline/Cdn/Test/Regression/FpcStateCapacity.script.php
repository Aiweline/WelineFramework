<?php
declare(strict_types=1);
require dirname(__DIR__,6).'/app/bootstrap.php';
use Weline\Cdn\Model\FpcPolicyState;
use Weline\Cdn\Service\FpcPolicyStateReducer;
use Weline\Framework\Database\Schema\{SchemaParser,SchemaDiffEngine,TableSchema,ColumnDefinition};
$declared=(new SchemaParser())->parse(FpcPolicyState::class);
$old=[];foreach($declared->columns as $col){$old[]=$col->name==='state_json'?new ColumnDefinition(...array_replace(get_object_vars($col),['type'=>'text'])):$col;}
$actual=new TableSchema($declared->tableName,$declared->comment,$old,$declared->indexes,$declared->foreignKeys,$declared->modelClass);
$targets=[];for($i=0;$i<302;$i++){$targets[]=['domain_id'=>1,'kind'=>'url','value'=>'https://www.example.invalid/'.str_repeat('scope-path/',12).$i];}
$json=json_encode(['jobs'=>['zone'=>['pending_targets'=>FpcPolicyStateReducer::accumulateTargets([],$targets,1)]]],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$engine=new SchemaDiffEngine();$ops=$engine->diff($declared,$actual,'mysql');
if(strlen($json)<=65535||count($ops)!==1||$ops[0]->kind!=='modify_column'||$ops[0]->payload->name!=='state_json'||$ops[0]->payload->type!=='longtext'){fwrite(STDERR,'FAIL actual schema must widen TEXT for lossless '.strlen($json).' byte target state; ops='.json_encode($ops)."\n");exit(1);}
if($engine->diff($declared,$actual,'pgsql')!==[]){fwrite(STDERR,"FAIL PostgreSQL text must remain compatible\n");exit(1);}
$connector=(new ReflectionClass(\Weline\Framework\Database\Connection\Adapter\Mysql\Connector::class))->newInstanceWithoutConstructor();
// Use the observed MySQL physical name; local PostgreSQL parser includes its public schema.
$sql=$connector->buildAlterModifyColumnSql('w_cdn_fpc_policy_state',get_object_vars($ops[0]->payload),get_object_vars($ops[0]->rollbackPayload));
if(!str_contains(strtolower($sql),'longtext')){throw new RuntimeException('Wrong MySQL DDL');}
echo 'PASS actual parser/diff/MySQL DDL, PostgreSQL compatibility, lossless '.strlen($json).' bytes / 302 targets: '.$sql."\n";
// Optional real local persistence exercise. Never targets singleton state_id=1 or production.
if(in_array('--persist-local',$argv,true)){
    if(realpath(BP)!=='/Users/weline/Project/Official/框架'){throw new RuntimeException('Local integration target mismatch');}
    $model=clone \Weline\Framework\Manager\ObjectManager::getInstance(FpcPolicyState::class);
    $tx=\Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface::class);
    $id=random_int(1000000000,2000000000);$rollback=new RuntimeException('capacity-test-rollback');
    try{$tx->runWrite($model->getConnection(),function()use($model,$id,$json,$rollback){
        if((clone $model)->clear()->where('state_id',$id)->select()->fetchArray()!==[]){throw new RuntimeException('Test ID occupied');}
        $model->clear()->setData('state_id',$id,true)->setData('state_json',$json)->setData('updated_at',gmdate('Y-m-d H:i:s'))->save();
        $row=(clone $model)->clear()->where('state_id',$id)->select()->fetchArray()[0]??[];
        if(($row['state_json']??null)!==$json){throw new RuntimeException('Actual ORM persisted payload differs');}
        throw $rollback;
    });}catch(Throwable $e){if($e!==$rollback){throw $e;}}
    if((clone $model)->clear()->where('state_id',$id)->select()->fetchArray()!==[]){throw new RuntimeException('Test row did not roll back');}
    echo "PASS actual local ORM write/read 88KB state and transaction rollback; singleton untouched.\n";
}
