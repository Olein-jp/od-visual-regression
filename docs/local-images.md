# 共通Imageと固定ローカル接続

Issue #38の製品結合基盤。管理画面/ViewerとMVP全シナリオは後続Issue、Cloud Runの実機・IAM・VPC検証は #39 の範囲である。

## Image

`npm run images:build` はlockfileから必要なworkspaceだけをインストールし、linux/amd64のRunner/Dispatcherを作成する。固定した公式Node 24.14.0/Playwright Imageを使用し、RunnerのPlaywright 1.64.0、Chromium 156.0.8078.4を実撮影で照合する。両入口はUID/GID 1000、read-only filesystemで動作する。WordPress、テスト、TypeScript、wp-env、ホストの秘密、設定ファイルを実行Imageへコピーしない。Runnerの公式ベースImageに含まれる他ブラウザは最終filesystemで除去するが、元のベースlayerは残る。

生成した `.odvr-local/images.json` の `odvr/runner@sha256:…` と `odvr/dispatcher@sha256:…` をcomposeへ渡す。タグの再解決やlocal専用Imageの再ビルドを行わない。CloudへはこのImageを再ビルドせずregistryへコピーし、同じmanifest digestをJob/Serviceへ指定する。Cloud配置・registryコピーの実施は #39 で扱う。秘密をbuild arg、Image label、Job envへ渡さない。

## wp-env

Docker/Compose、Node、opensslを使用する。Dockerはcontainerd Image storeを必要とする。Docker Desktopでは「Use containerd for pulling and storing images」を有効にする。従来のImage storeはload時にmanifest digestを失うため、タグやconfig digestへの代替を行わず起動前に拒否する。CIでは専用Docker daemonにcontainerd storeを設定し、既存ホストのDocker設定を自動変更しない。既存のこのrepositoryのwp-envを起動しておく。fixture専用の単一サイトで実行し、既存Basic認証のあるサイトは対象外とする。

```sh
npm ci
npm run env:start
npm run images:build
npm run local:start
npm run local:check
npm run local:fixture
npm run local:status
npm run local:stop
```

`local:start` は既存WordPress/CLIだけを専用bridge `172.30.238.0/24` に追加する。既存network・DBは再作成しない。TLS中継は固定IP `172.30.238.20`、Origin `https://wordpress.fixture.test:8443`、port 8443に限定する。中継のWordPress backendは `172.30.238.10:80`、Dispatcher backendはサービス名 `dispatcher:8080` に固定する。RunnerはDNSを再解決せず登録済みliteralへ接続し、実peer IP/portを照合する。別Origin/IP/port、localhost、redirect、cloud runtimeでのlocal設定を拒否する。

ホストの `.odvr-local/input` は700、JSON/fixture秘密鍵は600。非秘密登録・fixture CAだけを設定volumeへコピーし、全runtimeからread-onlyでmountする。Run Token/Basic/Shared Secretを設定JSONに記載しない。初期化プロセスだけがvolumeの所有者を準備し、その後UID/GID1000へ権限を落とす。初期化はtmpfsのmountを維持するため常駐する。

ダミーShared Secret・worker key・TLS鍵・Run Secretは32MiBのtmpfsに置き、秘密ファイルは600、directoryは700とする。秘密はログへ出さない。Firestore emulator・非秘密queueはnamed volumeに保存する。Dispatcher/workerへDocker socketを渡さず、launcherは固定入口だけを実行する。queueの起動markerを先に永続化し、同Executionで初回+retry1の最大2試行、Taskごと30分以内、Secret expiry以内を強制する。中断した2回目から3回目を作らない。終了済み旧digestの履歴は参照できるが、未終了の別digestは起動しない。

fixtureは管理APIで専用Device/Suite/Target/Runを作成し、実署名受付、固定HTTP撮影、raw multipart Upload、Completeを通す。固定Runner経路のWordPress転送を明示し、既存のパーマリンク設定に依存しない。起動時にはダミーBearerの401とgateway能力を確認してからfixtureを開始する。Apacheは元のRunner要求だけで `enable_post_data_reading Off` を有効化し、WordPressへの内部転送後も保持する。Storage保護の運用確認フラグを一時設定し、実canaryの公開control200と非公開領域403/404を接続診断で検査する。TLS検証はダミーCAを信頼し、無効化しない。

`local:stop` はfixture Runを既存Retentionで削除し、Device/Suite/Target、専用session、変更したoptionを回収する。一時wp-configとWordPress側のダミー秘密は、既存wp-envのApache実行UID/GIDに合わせる。wp-configは変更前の内容・所有者・権限へ戻し、fixture MU-plugin/Apache設定/ダミー秘密だけを削除し、追加したbridge接続だけを外す。ホストに生成したfixture CAと秘密鍵も削除する。実行中Runがあると削除を拒否するため、完了を確認してから停止する。既存wp-envは起動したまま残る。前回の状態がある場合は新しい起動を拒否し、停止による復元を先に行う。

## 再起動・制限

`npm run test:local` は上記操作を一括実行し、Complete/CAPTURED、固定先以外の拒否、tmpfs/600、秘密cleanup、Dispatcher/launcher再起動、emulatorの正常停止時export/importによる受付記録保持を確認して復元する。launcherの未知起動/中断/retry上限はRunnerテスト、受付送信後の起動不明・Operation照合はDispatcherテストも合わせて検査する。

emulatorは固定project/databaseを明示した公式export APIを呼び、export完了後に終了する。単にプロセスを終了するだけではexportが完了しないため、停止用supervisorがこの順序を管理する。強制killやホスト障害でexport前の変更が失われる可能性があり、Cloud Firestoreの耐久性を再現するものではない。tmpfsが消失した未終了Runは秘密を再発行せずfail closedになる。queue/Firestoreのvolumeは通常停止では残す。Image更新は実行中Runのない状態で行う。fixture CAの有効期間は7日で起動時に生成する。

## Local等の既存環境

この自動fixtureはwp-envを検出する。Localのネイティブlocalhost/可変portへ自動接続する機能は提供しない。LocalのWordPressを既存のまま上記専用bridgeの固定backendへ接続できる場合は、固定中継とread-only設定を同じ構成で使用できる。管理者がWordPress側のCA・Runner経路・Storage保護・接続診断を準備する必要がある。固定単一Origin/literal RFC1918 IP/portを維持できない環境は非対応であり、private IP全体やlocalhostを許可して回避しない。今回の実測はwp-envで行い、Localアプリの実機接続は未検証である。
