# WelineFramework

[언어](./README.md) | [简体中文](../../README.md)

WelineFramework는 모듈형 웹 애플리케이션, 관리자 시스템, 커머스 시나리오를 위한 PHP 프레임워크입니다. 모듈, 라우팅, ORM, 이벤트/Hook, 테마, 백엔드 ACL, i18n, WLS 장기 실행 서비스, CLI 도구를 정리하여 비즈니스 모듈을 확장 가능하고 유지보수하기 쉽게 만듭니다.

## 경로 선택

- 새 로컬 환경: 원클릭 설치 프로그램을 사용합니다.
- PHP, Composer, 데이터베이스가 이미 있음: 클린 설치를 사용합니다.
- 아키텍처: [Weline architecture](../weline/README.md).
- AI / Codex 작업: [AI-ENTRY.md](../../AI-ENTRY.md)에서 시작합니다.

## 요구 사항

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL
- Nginx / Apache 또는 Weline 내장 서버(WLS)

설치 명령은 현재 사용자로 실행하세요. 원클릭 설치 프로그램을 `sudo`로 직접 시작하지 마세요.

## 원클릭 설치

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

자주 쓰는 옵션: `-b dev`, `-y`, `-f`, `--path-only`, `php`, `pgsql`, `mysql`.

## 클린 설치

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
php bin/w system:install:sample
```

Weline 내장 서버(WLS) 시작:

```bash
php bin/w server:start
```

## 유용한 명령

| 명령 | 용도 |
|---|---|
| `php bin/w` | 명령 목록 |
| `php bin/w setup:upgrade` | 모듈, 스키마, 설정 업그레이드 |
| `php bin/w setup:upgrade --route` | 컨트롤러 변경 후 라우트 갱신 |
| `php bin/w server:start` | Weline 내장 서버(WLS) 시작 |
| `php bin/w query:help <provider>` | Query Provider 계약 확인 |

## 문서

- [프로젝트 문서](../README.md)
- [아키텍처 개요](../weline/架构总览.md)
- [개발 가이드](../开发文档.md)
- [배포 가이드](../部署文档.md)
- [AI 어시스턴트 입구](../../AI-README.md)

## 참고

`generated/` 산출물을 직접 편집하지 마세요. `routes.xml`을 수동으로 작성하지 마세요. 사용자에게 보이는 문구는 i18n을 거쳐야 합니다. AI 테스트는 기본 포트 `9501`이 아니라 `9502+`의 격리된 WLS 인스턴스를 사용해야 합니다.
