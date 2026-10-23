;; Weline AI 获客浏览器桥 —— 策略内核（WASM）
;;
;; 职责边界：只做纯计算（打分、循环预算、检索槽位），
;; 网络 I/O、模型调用与 SSE 传输全部由 background.js 负责。
;;
;; 导出：
;;   score_lead(a,b,c,d,e)      5 轴各 0–2，返回 0–10
;;   should_continue(iter,leads,max)  1=继续，0=停止
;;   next_query_slot(used,total)      返回下一个槽位下标，耗尽返回 -1

(module
  ;; 单轴钳制到 0–2
  (func $clamp_axis (param $value i32) (result i32)
    (if (result i32) (i32.lt_s (local.get $value) (i32.const 0))
      (then (i32.const 0))
      (else
        (if (result i32) (i32.gt_s (local.get $value) (i32.const 2))
          (then (i32.const 2))
          (else (local.get $value))))))

  ;; 5 轴资格打分，单轴 0–2，合计 0–10
  (func (export "score_lead")
    (param $axis_a i32) (param $axis_b i32) (param $axis_c i32) (param $axis_d i32) (param $axis_e i32)
    (result i32)
    (i32.add
      (i32.add
        (call $clamp_axis (local.get $axis_a))
        (call $clamp_axis (local.get $axis_b)))
      (i32.add
        (i32.add
          (call $clamp_axis (local.get $axis_c))
          (call $clamp_axis (local.get $axis_d)))
        (call $clamp_axis (local.get $axis_e)))))

  ;; 是否继续循环：轮次与线索数都未到上限才继续
  (func (export "should_continue")
    (param $iterations i32) (param $leads i32) (param $max i32)
    (result i32)
    (if (result i32)
      (i32.and
        (i32.lt_s (local.get $iterations) (local.get $max))
        (i32.lt_s (local.get $leads) (local.get $max)))
      (then (i32.const 1))
      (else (i32.const 0))))

  ;; 下一个检索槽位：used 在 [0,total) 内返回 used，否则 -1
  (func (export "next_query_slot")
    (param $used i32) (param $total i32)
    (result i32)
    (if (result i32)
      (i32.and
        (i32.ge_s (local.get $used) (i32.const 0))
        (i32.lt_s (local.get $used) (local.get $total)))
      (then (local.get $used))
      (else (i32.const -1)))))
