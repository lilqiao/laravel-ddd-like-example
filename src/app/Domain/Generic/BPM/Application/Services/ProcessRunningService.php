<?php
/**
 * Created by IntelliJ IDEA.
 * User: 1x481n
 * Date: 2022/6/14
 * Time: 6:02 PM
 */

namespace App\Domain\Generic\BPM\Application\Services;

//use App\Domain\Generic\BPM\Application\Jobs\QueueableClosure;
use App\Domain\Generic\BPM\Models\BPMTransaction;
use App\Domain\Generic\BPM\Services\SourceHandler;

//use App\Domain\Generic\User\Services\UserService;
use App\Models\Business\BusinessType;
use App\Models\User;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * 流程运行相关服务
 */
class ProcessRunningService extends BaseService
{
    private bool $async = false;

    /**
     * 发起流程
     *
     * @link http://172.16.4.177:7700/#/view/W2o5a1XD
     *
     * @desc 表单中的上下文动态值，先舍弃中台配置规则【回调函数，回调url】等较为复杂的实现方案。
     * 新增用法（建议采用）：SourceHandler的继承类中，同时选择实现 WithContextFormMap，根据流程各节点表单规则实现
     * 场景一：业务侧自动发起审批，直接调用本服务，自行根据对应审批模板的表单规则，在上下文中预先赋值给动态表单字段 。
     *【暂无场景，不做】场景二：手动发起审批入口，在接口路由中使用中间件，手动维护映射，调用各业务侧上下文，组装动态表单值。
     *
     * @param BusinessType $bizType 某个业务模型类
     * @param SourceHandler $sourceHandler 各调用侧自己的来源处理类，可包含上下文表单逻辑(需要实现 WithContextFormMap)
     * @param int $startUserId 发起人用户ID
     * @param int $sourceId 业务侧来源表的主键ID
     * @param string $sourceNo 业务侧来源表的来源编号
     * @param int $storeId 指定发起流程所属的门店id
     * @param int $areaId 指定发起流程所属的区域id
     * @param array $formData 表单数据，业务侧基于前端提交收集的数据 + 基于规则需要根据上下文二次补全的数据
     * @param array $ext 额外字段 ['ext1'=>,'ext2'=>,'ext3'=>]
     * @param string|null $title 可定制的审批标题，缺省取BusinessType的name
     * @param int|null $orgLevel 指定发起流程的组织层级 1总部 2大区 3门店
     * @param bool $executeFirstNode 是否执行首节点 默认true 查看审批流配置自己甄别
     *
     * @return array 返回说明：
     * [
     *  'bpm_transaction_sn'=>'本地bpm交易流水号'
     * ]
     * @throws Exception
     */
    #[ArrayShape(['bpm_transaction_sn' => "\Illuminate\Database\Eloquent\HigherOrderBuilderProxy|mixed|string", 'bpm_result' => "array"])] public function startProcess
    (
        BusinessType  $bizType,
        SourceHandler $sourceHandler,
        int           $startUserId,
        int           $sourceId,
        string        $sourceNo,
        int           $storeId,
        int           $areaId,
        array         $formData = [],
        array         $ext = [],
        ?string       $title = null,
        ?int          $orgLevel = null,
        bool          $executeFirstNode = true
    ): array
    {
        // 发起人相关信息
        //$startUser = app(UserService::class)->getUserWithRoleDepartment($startUserId); //移除部分逻辑
        // 假数据
        $startUser = User::query()->find(1);

        // 维护本地bpm交易记录表
        $bpmTransaction = BPMTransaction::query()->create(
            [
                'title' => $title ?: $bizType->name,
                'process_code' => $bizType->bpm_code,
                'source_type' => $bizType->value,
                'source_id' => $sourceId,
                'source_no' => $sourceNo,
                'org_level' => $orgLevel ?: $startUser->currentRole->type,
                'store_id' => $storeId,
                'area_id' => $areaId,
                'start_user_id' => $startUserId,
                'transaction_sn' => date('YmdHis') . substr(strval(microtime(true) * 10000), -4, 4) . rand(1000, 9999),
                'transaction_snapshot' => [
                    'startUser' => $startUser->toArray(),
                    'formData' => $formData,
                    'variables' => [],
                    'ext' => $ext,
                    'executeFirstNode' => $executeFirstNode
                ],
                'source_handler' => $sourceHandler,
            ]
        );

        // 待处理
        $processing = function () use ($bpmTransaction) {
            $transactionSnapshot = $bpmTransaction->transaction_snapshot ?? [];
            $formData = $transactionSnapshot['formData'] ?? [];
            $copyIds = [];
            // 预处理
            $this->preStartProcess($bpmTransaction, $nextUserVars, $formData, $copyIds);
            $transactionSnapshot = $bpmTransaction->transaction_snapshot;
            $transactionSnapshot['formData'] = $formData;
            $transactionSnapshot['variables'] = $nextUserVars;
            $transactionSnapshot['copyIds'] = $copyIds;
            $bpmTransaction->fill(['transaction_snapshot' => $transactionSnapshot])->save();
            // 远程调用
            return $this->engine->startProcess($bpmTransaction);
        };

        if ($this->async) {
            // 异步
            //dispatch(new QueueableClosure($processing));
            dispatch(new SerializableClosure($processing));
            $bpmResult = [
                'code' => 0,
                'message' => '流程发起中，请留意后续结果...'
            ];
        } else {
            // 同步
            $bpmResult = $processing();
            if ($bpmResult['code'] != 0) {
                abort(500, 'java工作流服务：' . $bpmResult['message'] ?? '未知错误！');
            }
        }

        return [
            // 交易流水号
            'bpm_transaction_sn' => $bpmTransaction->transaction_sn ?? '',
            'bpm_result' => $bpmResult
        ];
    }

    public function async(): static
    {
        $this->async = true;
        return $this;
    }

    /**
     * 组装发起流程之前所需的所有数据
     *
     * @param BPMTransaction $bpmTransaction
     * @param $nextUserVars
     * @param $formData
     * @param $copyIds
     * @return void
     * @throws Exception
     */
    private function preStartProcess(BPMTransaction $bpmTransaction, &$nextUserVars, &$formData, &$copyIds)
    {
        $nextUserVars = [];
        // 远程调用
        $result = $this->engine->getProcessStartVar($bpmTransaction->process_code);
        if ($result) {
            $nextRoleVars = $result['data']['nextRoleVars'] ?? [];
            $copyRoleVar = $result['data']['copyRoleVar'] ?? [];
            // 组装BPM所需变量参数(目前仅使用角色转人)
            if ($nextRoleVars) {
                $nextUserVars = $this->getNextUserVariables($nextRoleVars, $bpmTransaction->store_id, $bpmTransaction->area_id);
            }
            if ($copyRoleVar) {
                $copyIds = array_unique(
                    array_merge(
                        $copyIds,
                        $this->getNextUserVariables($copyRoleVar, $bpmTransaction->store_id, $bpmTransaction->area_id)['copyIds'] ?? []
                    )
                );
            }
            // 组装上下文表单
            $nodeId = $result['data']['nodeId'] ?? '';

            // 表单处理
            $this->handleForm($bpmTransaction, $nodeId, $formData);
        }
    }


}