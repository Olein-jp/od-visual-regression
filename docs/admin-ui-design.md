# Tests・Runs・Devices・Settingsの管理画面設計

対象は [Issue #11](https://github.com/Olein-jp/od-visual-regression/issues/11)。根拠は [仕様書 v1.1](specification-v1.1.md) §20〜26・§52〜55・§70〜73、および寸法差の§56、権限の§36、Environmentの§27。採用済みの [DB・ライフサイクル設計](data-lifecycle-design.md) と [管理API・認証・Storage設計](api-security-storage-design.md) を前提とする。本書は設計案であり、PRで採用された後に実装Issueへ分割する。製品コード・Schema・クラウドリソースは変更しない。

## 現状・推奨方針

- `wordpress/od-visual-regression/od-visual-regression.php` と直接参照するPlugin/Capabilitiesは初期化・翻訳・`manage_odvr`・公開コンテンツ選択APIを提供する。管理メニュー、DB、Suite/Run/Device/Settings API、認証画像配信は未実装。
- `packages/shared/src/index.ts` はDeviceプリセット、CaptureSettings、差分境界を定義する。現行Device/Prototype Schemaは入力範囲の参照元であり、製品APIを実装済みとは扱わない。
- Runnerの比較処理は最大幅×最大高さへの透明補完、寸法差領域の差分計上を実装する。Viewerは保存済み結果を表示し、ブラウザ側で差分率や判定を再計算しない。
- WordPressが管理・保存、Dispatcherが受付・起動、Runnerが撮影・比較を担当する。画面は同一サイトの管理APIだけを呼び、DispatcherやRunnerへブラウザから直接接続しない。
- 管理メニューはTests / Runs / Devices / Settingsの4つ。Testsを初期画面にし、WordPress管理画面内の専用ページで表示する。Multisiteも現在のサイト単位であり、ネットワーク横断画面は作らない。

## 管理者の操作フロー

1. SettingsでDispatcher URL・site_id・秘密設定の有無・Storage準備を確認し、接続確認を行う。Devicesでプリセットまたは任意Viewportを確認する。
2. Testsの「新規作成」からSuite名、Post Type→Target→Device→差分・撮影設定の順に入力して保存する。Custom URLはTarget選択内で追加する。
3. 「Run Test」でBaseline方式を選ぶ。初回はPinned未設定またはPrevious候補なしで比較なしの撮影を行い、202のRun UUIDを受けてRuns詳細へ移動する。
4. 全件撮影成功のcomplete Runを確認し、「Baselineに設定」でPinnedを作る。サイト更新後、同じSuiteのRun TestでPinnedを選び、新しいRunを開始する。
5. Runs詳細のEnvironment差分、Target×Device結果、ViewerのBefore/After/Diff/Overlayで確認する。確認したcomplete Runを明示的にBaselineへ昇格できる。CHANGEDがあっても撮影成功ならcompleteであり、自動昇格はしない。

「未保存の変更があります」を表示してRun開始を無効にする。保存途中の失敗、接続不明、撮影失敗を画面遷移だけで成功として扱わない。更新操作そのものやWordPressログイン後の撮影は本機能に含めない。

## 画面操作と管理APIの対応

以下は `/wp-json/odvr/v1` からの相対。既存content API以外は先行設計で確定した将来契約。新規JSONは`schema_version: 1`、単一応答は`item`、一覧は`items`。content APIは既存の配列応答を維持する。IDは1〜2147483647、一覧page既定1/per_page既定20・最大100、件数は`X-WP-Total` / `X-WP-TotalPages`を使う。

| 画面・操作 | method・ルート | 表示・更新規則 |
|---|---|---|
| Tests一覧・詳細・新規・編集 | GET/POST `/suites`、GET/PATCH `/suites/{id}` | name/settings/device_idsを扱う。POST=201、PATCH=200 |
| Testsのアーカイブ | DELETE `/suites/{id}` | 200でarchived。実行中409。履歴は保持し、新Runを禁止 |
| 投稿タイプ・公開投稿の選択 | GET `/content/post-types`、GET `/content?post_type=…&search=…&page=…&per_page=20` | 既存API。下書き・非公開・パスワード付き投稿を除外。show_in_rest=falseの公開CPTも含む |
| Targetの取得・追加・編集・削除 | GET/POST `/suites/{id}/targets`、PATCH/DELETE `/suites/{id}/targets/{target_id}` | 別Suiteは404。削除はenabled=false、再追加は新ID |
| Run開始・Suiteの履歴 | POST/GET `/suites/{id}/runs` | POST bodyはbaseline_mode=pinned/previous/specific、specificではreference_run_id必須。202のrun_uuidを使う。Run一覧はcreated_at/id降順 |
| Runs詳細・結果 | GET `/runs/{uuid}`、GET `/runs/{uuid}/snapshots` | 固定Manifest由来のラベル/Device、Environment、集計、結果を表示 |
| Baseline設定・昇格 | POST `/suites/{id}/baseline` | run_idを送り200。同Suitecomplete・全画像可読が条件。履歴参照は変えない |
| Run削除 | DELETE `/runs/{uuid}` | 保護再検査後202 deleting、保護/実行中は409。削除完了はGETの404で確認 |
| Viewer画像 | GET `/snapshots/{id}/image?kind=current\|baseline\|diff` | Cookie/nonce付きfetchでPNGをBlob取得。baselineは当該Snapshotの固定参照であり現在のPinnedではない |
| Devices一覧・新規・編集・無効化 | GET/POST `/devices`、GET/PATCH/DELETE `/devices/{id}` | slug変更・Suite参照中の無効化409。過去Runには影響しない |
| Settings表示・保存 | GET/PATCH `/settings` | 非秘密設定だけ保存。秘密フィールド送信400、GETは設定有無のみ |
| 接続確認 | **追加提案** POST `/settings/connection-test` | 下記の診断契約。#4の既存ルートに存在せず、採用・実装前に契約追加が必要 |

確定契約に全Suite横断のGET `/runs`はない。Runs一覧はSuiteセレクターを必須とし、Testsからの遷移では選択済みにする。Suite名で検索する新APIも仮定せず、Suite一覧のページングで選択する。Baseline解除APIもないためMVPに解除ボタンは置かない。比較なしの初回撮影と、Pinned設定/置換、実行時Previous/Specific選択を提供する。

一覧の最終ページが削除等で空になったら前ページを再取得する。画面フィルターは取得したページ内であることを示し、全件の検索結果と誤認させない。Target×Device結果は最大1000件までページを順に取得し、取得途中は件数を表示、未取得をPENDINGと推定しない。

## Tests一覧・Suite編集・Run Test

### Tests一覧

Suite名、active/archived、有効Target数、選択Device数、Pinned Baseline、Last Run状態、Edit、Run Testを表示する。基礎APIに集約値の応答フィールドは未定義なので、先行実装でSuite応答に集約情報を追加するまでは、表示ページ内のSuiteについてTargetと最新Runを取得する（同時要求上限4）。未取得は「読込中」、失敗は「取得できません」とし、0件と区別する。

空一覧には新規作成への導線を置く。アーカイブはSuite名と履歴保持を示す確認ダイアログを経由する。archivedではEdit/Run Testを無効にし、履歴確認を残す。復元APIは本設計で追加しない。

### Suite編集

Suite名は必須・200文字以内。4段階は見出しと戻る/次へで移動でき、入力をメモリ内に維持する。保存前の概要にはTarget数×Device数、適用設定、許可Originを表示する。

| 段階 | 入力・動作 |
|---|---|
| 1 Post Type | 公開Post Typeの選択。選択を変えても既に追加したTargetは消さない |
| 2 Target | 検索・ページング・複数選択と選択済み一覧。ラベル/URLを確認して追加・削除・順序変更。異なる投稿タイプを同じSuiteに追加可。Custom URLはラベルと絶対HTTP(S) URLを入力 |
| 3 Device | 有効Deviceのチェックボックス。name/viewport/UA概要を表示し、Devicesへのリンクを提供。1〜10件必須 |
| 4 差分・撮影設定 | pixel_threshold、review_threshold、changed_threshold、Ignore Selector、Timeout、Lazy Load、concurrency、allowed_origins。既定値と入力範囲は下表 |

Targetは1〜100、最大1000 Snapshot。Custom URLは既存`ODVR_Target_URL::validate()`の2048バイト・userinfo禁止等の契約を使う。形式検証の成功は撮影許可を意味しない。許可Originを表示し、追加のリソースOriginは管理者が1〜30件の正規化HTTP(S) Originとして明示する。SSRF制限の無効化スイッチは設けない。

保存時とRun開始時にサーバーで公開状態・permalink・Device有効性を再検証する。投稿が消えた/非公開、Deviceが無効なら該当行を示し修正を求める。Custom URLの到達性を保存時のブラウザ通信で検査しない。異なるTarget IDの同一URLは別Targetとして扱い、UIでURLだけによる自動統合はしない。

| 設定 | 既定値・入力範囲・説明 |
|---|---|
| navigation_timeout_ms | 30000、整数100〜120000 ms |
| image_timeout_ms | 10000、整数100〜60000 ms |
| lazy_load | true。撮影前のスクロール/待機を有効にする |
| concurrency | 2、整数1〜4。Runner内のBrowser並列数でありCloud Run Task数ではない |
| pixel_threshold | 0.2、有限数0〜1。pixelmatchの画素感度 |
| review_threshold / changed_threshold | 0.001 / 0.01。表示は0.1% / 1%、保存は0〜1の比率、review < changed必須 |
| ignore_selectors | 1行1 selector、最大50件・各1〜500文字。空行を除く。data-odvr-ignoreも撮影時に対象 |

判定は`ratio <= review`がUNCHANGED、`review < ratio < changed`がREVIEW、`ratio >= changed`がCHANGED。0.1%ちょうどと1%ちょうどの境界を説明する。セレクターは文字列範囲をサーバー検査し、実ページでのCSS構文不正/撮影失敗は結果エラーとして扱う。fullPage/PNG/scale=css/animations=disabled/caret=hideは固定であり任意変更するフォームは作らない。

サイトの撮影既定値は新Suiteへコピーし、既存SuiteやRunへ自動適用しない。Suiteの撮影設定は保存済み実値であり、実行時にSettings変更が混入しない。

SuiteとTargetを一括保存するAPIはない。新規はPOST Suite→Targetを順にPOST→再読込、編集はSuite PATCH→差分TargetのPOST/PATCH/DELETE→再読込とする。全成功まで「保存済み」にせずRun開始を無効にする。途中失敗は成功済み項目と未保存項目を明示し、GETで状態を確認後、未反映操作だけを再送する。POST応答喪失時に自動再送してSuite/Targetを重複作成しない。自動rollbackや黙ったDELETEも行わない。原子的な一括保存や楽観ロックが必要なら別API設計が必要であり、MVPでは同時編集による上書きの限界を表示する。

### Run Test

確認ダイアログでSuite、Target×Device、Baseline方式、候補Run、秘密を除く実行設定を表示する。Pinnedは設定済みRunまたは「未設定・比較なし」、Previousは「開始時点の最新completeをサーバーで選択」、Specificは同Suitecomplete一覧からIDを選ぶ。候補表示から実行までの変更はサーバーで再検査する。Pinned不正を比較なしに読み替えない。

送信中はボタンを無効化。同Suite queued/runningは409を表示し、履歴に戻る導線を出す。202は受付履歴の作成を意味し、撮影成功とは表示しない。受付不明でもUUIDが返ればそのRunを追跡する。POST自体の応答喪失では同Suite履歴を再取得して確認し、Runの有無が不明なまま自動で再実行しない。取消・強制再dispatch・個別Snapshot上書きは提供せず、撮影失敗後の再実行は新Runにする。

## Runs一覧・詳細・Baseline・Retention

Runs一覧は選択Suiteの履歴を新しい順に表示し、日時（サイトの表示タイムゾーン）、Run ID/UUID、Run状態、成功/エラー/全件、参照Run、詳細へのリンクを持つ。日時はAPIのUTCから変換し、タイムゾーンを明示する。現在のPinnedと各Runの開始時reference_run_idは別欄で表示する。

詳細は開始時Suite名・Targetラベル/URL・Device全設定・撮影設定を固定Manifestから表示する。現在のSuiteやDeviceを読み直して過去の値に置換しない。

- 進捗は `(completed_snapshots + error_snapshots) / total_snapshots`。成功・エラー・pendingを併記する。CHANGED/REVIEWも撮影成功に含む。100%でもComplete応答前はrunningであり「完了」にしない。
- queuedは受付/開始待ち、runningは撮影中、completeは全件撮影成功、partialは一部撮影失敗、failedは全件失敗またはRun全体の異常、deletingは削除処理中。Runのerror_codeと期限を表示する。queued15分/総90分の既定期限に達してもブラウザ側で状態を確定せず、GETによるサーバー判定を待つ。
- Targetを行、Deviceを列とする結果表に、PENDING/CAPTURED/NO_BASELINE/UNCHANGED/REVIEW/CHANGED/ERRORの文字、差分率、寸法変更表示を置く。横スクロールと行/列見出しを保持する。セルをボタンにしてViewerへ移動する。
- ERRORは定型メッセージ、HTTP Status（nullなら応答なし）、duration、エラーコード、許可された診断情報を表示する。生の例外やログ、資格情報付きURLを描画しない。現在のRunner詳細コードと#4の製品Uploadコードには差があるため、製品実装で対応を確定し、未知コードは安全な共通メッセージとcode文字列で表示する。
- Environmentは開始時reference Runとの比較。WordPress/PHP、テーマ/親テーマ、プラグインの追加/削除/Version変更、MU Plugins、Locale/Site URL、Runner/Playwright/Chromiumを扱う。識別子で対応付け、Version不明nullは「不明」、Runner補完前は「未取得」。参照なしは「比較対象なし」。環境差分で撮影判定を変更しない。

Baseline設定/昇格は同Suitecompleteだけに表示し、全画像可読をサーバーで確認する。確認ダイアログに現在と新しいPinnedを示し、成功後Suiteを再取得する。partial/failed/deletingは候補外。completeのCHANGEDを理由に候補から除外しない。既存RunのBeforeやreferenceは昇格で変化しない。新Target/Device、撮影条件不一致、欠損/破損はNO_BASELINEの理由を示す。

削除の確認はRunと画像の削除、参照保護、最新complete手動削除によるPrevious候補変更を示す。Pinned、queued/running、保持Runから参照されるRunは削除不可。サーバーの再検査で409になったら状態と保護理由を再取得する。202では行を消さずdeletingを表示し、404で完了確認。削除失敗はdeletingを維持し、自動処理の再試行を待つ。新しい削除再開APIを仮定しない。

Retentionは各SuiteのKeep All / Last 10 / Last 20 / Custom正整数N。自動処理は最新N、実行中、Pinned、最新completeおよびそれらの参照閉包を保護する。Last Nは上限ではなく最新N件以上の保持。手動削除は最新N/最新complete/Keep Allという自動保持条件だけでは禁止されないが、Pinned/実行中/参照の保護は必須。保護理由・保持件数・容量・削除失敗をRunsとSettingsに表示する。集計/保護理由/容量のAPIフィールドは後述の追加事項として確定が必要。

## Snapshot Viewer

初期モードは比較可能ならDiff、比較なしならAfter。モード切替・閉じる・前/次セルへの移動を提供し、Run/Target/Device、双方の元寸法、dimension_changed、差分率/画素数/total_pixelsを表示する。

| モード | 表示規則 |
|---|---|
| Before | 当該Snapshotの固定baseline画像。現在のPinnedを参照しない |
| After | current画像 |
| Diff | Runnerが保存したdiff画像。ブラウザ再計算なし |
| Overlay | Before/Afterを同じ原点で重ね、0〜100%のSliderでAfterの表示領域を変える。0%はBefore、100%はAfter |

寸法が違う場合は両方を拡大縮小して合わせず、左上原点・共通の最大幅×最大高さに配置する。不足領域は透明として市松背景で示す。Before/Afterは元画像の境界も表示し、Overlayは共通倍率を用いる。「画面に合わせる」と100%表示を提供し、縮小倍率を表示、縦横スクロールを維持する。Diffは正規化済みの共通寸法。寸法変化は差分率が低くても独立した表示を残す。

| 結果・取得状態 | 表示・モードの可否 |
|---|---|
| PENDING | 撮影待ち。画像なし、全モード無効 |
| CAPTURED | 比較Runなし。Afterのみ、差分率は「比較なし」 |
| NO_BASELINE | missing/corrupt/incompatible/new_target/new_device等の理由。Afterのみ、差分率は「比較できません」。0%やUNCHANGEDにしない |
| ERROR | 定型撮影エラー。画像なしの場合全モード無効、取得可能性はAPIの画像有無に従う。別Run画像で補わない |
| 読込中・PNG decode失敗 | モードごとに読込/破損を表示。失敗した画像を使うモードだけ無効化し、再取得ボタンを提供 |
| 401/403・nonce期限切れ | 「認証を確認するため画面を再読み込みしてください」。全画像を破棄し自動再試行/ポーリング停止。画像URLにnonceを付けない |
| 404 | 欠損・削除中・参照不可を区別できる取得情報で示す。保管障害を撮影時NO_BASELINEへ書き換えない |
| 429/503・通信障害 | Retry-After等に従い有限回の再取得。成功した他モードは利用可、失敗状態を隠さない |

取得は`credentials: same-origin`、`X-WP-Nonce`付きfetch→PNG Content-Type確認→Blob→object URL。JSON/HTMLのエラー本文を画像にしない。巨大PNGは現行比較上限40,000,000 pixelsを前提にメモリ負荷があるため、現在の1セル分だけ保持し、一覧全件の画像を先読みしない。Before/After/Diffを必要なモードで遅延取得する。上限内でもブラウザのdecode失敗は明示する。

切替/閉じる/ページ終了時にAbortControllerで未完要求を中止し、全object URLを`URL.revokeObjectURL()`で解放する。切替後に旧レスポンスが届いても世代IDで破棄する。LocalStorage/IndexedDB/Service Workerや共通キャッシュへ画像を保存しない。取得エラー時にも該当URLを解放する。権限失効前に取得済みの画像を利用者の端末から回収できるとは保証しない。

## Devices

Desktop 1440×900（touch=false）、Tablet 768×1024（touch=true）、Mobile 390×844（mobile/touch=true）を初回投入する。いずれもscale=1、Desktop/Tabletのmobile=false。現在のsharedと一致させ、名称だけでUAを推測しない。再有効化時に編集値をリセットしない。

一覧/編集にname、slug、viewport_width/height、user_agent、device_scale_factor、is_mobile、has_touch、enabled、sort_orderを持つ。name1〜100文字、slugは`^[a-z][a-z0-9-]{0,49}$`で一意かつ作成後変更不可、viewport各整数1〜4096、UA最大1000文字・空ならChromium既定、scale有限数0.1〜4。enabledは無効化操作、sort_orderは非負整数とする。

共有Deviceの編集は今後のRunすべてに影響するため、保存確認で共有設定であることを示す。使用中の無効化409では参照するSuiteへの導線を出す（応答の利用箇所フィールドは要確定）。過去Manifestは変わらない。Suite選択上限10とサイトのDevice登録総数を混同しない。UA変更だけで実端末の再現が保証されるとは表示しない。

## Settings・接続確認・秘密情報

非秘密設定のDispatcher URL、site_id、撮影既定値、queued/Run期限、Retentionを保存する。Dispatcherは登録済みHTTPS URLに制限、redirect禁止。queued15分・総90分が既定、Run期限はToken2時間未満であることに加え、採用されるCloud Run/秘密TTL設計の制約を検証する。並列数はTask数を増やさない。

Shared Secretは#4に従ってwp-config/ホスト秘密注入のみ。入力欄・再表示・「表示する」ボタンを作らず、`dispatcher_secret_configured`の設定有無と運用者向け手順を出す。WordPress側のShared Secret定数名は#4で未定義なので、実装契約で確定して文書化する。画面初期データにも平文を載せない。

HTTP Basic認証も登録フォームを作らず、`http_auth_configured`と`http_auth_origin`を表示する。設定は`ODVR_HTTP_AUTH_USER` / `ODVR_HTTP_AUTH_PASSWORD` / `ODVR_HTTP_AUTH_ORIGIN`。両方未設定なら無効、片方/空/不正Originは実行不可。許可HTTPS Origin1つ、MVPはログアウトしたFrontendのみ。Runner API経路をフロントのBasic保護から除外し、Bearer検証を必須にする。秘密の変更は実行停止中に行う。秘密フィールドのPATCHはUI外からでも400。

接続確認は未保存値を使わず、Settings保存後に明示ボタンで行う。追加提案POST `/settings/connection-test`はbodyを`{schema_version:1}`だけとし、任意URL/Tokenを受け取らない。Cookie/nonce/manage_odvr、no-store、レート制限を適用する。WordPressで保存設定・秘密設定の有無・Storage canary・DB Versionを検査し、サーバーから固定DispatcherへHMAC署名付きの専用診断を送る。

Dispatcher側の**追加提案**POST `/v1/connection-test`はsite_id/schema_version/callback_baseだけを受け、#4のHMAC・登録callback照合を適用する。Job/Run/Run Token/Secretを作らず、登録と署名検証の成功だけ返す。応答は管理APIから`item.checks`（settings/storage/dispatcher各passed/failed）、`checked_at`、定型code/messageとして返し、Google応答や秘密を返さない。Dispatcherでのcallback値一致は実際の到達性を証明しない。

診断成功はRunnerの実IAM・Basic経路・撮影先・Uploadまでの疎通確認ではないことを示す。全経路の検証は管理者が新Runを明示実行する。専用診断ルートが採用・実装されるまでは接続確認を無効にして「対応するDispatcherが必要」と表示し、既存POST `/v1/jobs`を診断目的に流用しない。

## 権限・翻訳・アクセシビリティ・ポーリング・build

- 4メニューとページ表示、全管理API、全画像に`manage_odvr`を適用する。メニューを隠すだけでアクセス制御としない。Cookie + wp_rest nonceを各要求で検査し、Runner Tokenで管理APIを呼べない。権限なし/セッション失効は停止し再ログインを案内する。
- 文字列/ラベル/URL/エラーはtextとして描画、URLは許可スキームを確認する。秘密、Token Hash、内部Storageパス、資格情報、nonceをログ/URL/HTML/翻訳データへ出さない。既存APIが返すサイト内限定の値だけを使用する。
- PHP/JSは`od-visual-regression`の同じtext domainを使い、JSは`@wordpress/i18n`と`wp_set_script_translations()`で読み込む。複数形、日時/数値、%表示、定型エラーを翻訳対象にする。コード/UUID/slugは翻訳しない。
- 各フォームにlabel、単位、説明、aria-describedby/aria-invalidを付ける。エラー概要から該当入力へ移動できる。進捗はprogressbarと数値、状態は文字とアイコンで示し色だけに依存しない。ポーリングでフォーカスを移動しない。aria-liveは状態変更・集計変更だけを穏やかに通知する。
- Viewerはダイアログとして名前・フォーカストラップ・Escape・閉じた後のセルへの復帰を実装。4モードはtab/tabpanelと左右キー、Overlayはラベル付きnative rangeを使い矢印/Home/Endで操作できる。Target並べ替えはドラッグだけにせず上下ボタンを用意する。倍率200%、狭い画面、キーボードのみ、スクリーンリーダーで確認する。
- activeなRun詳細は5秒間隔でRunと結果を取得する。同時pollは1組だけ、結果はページングし確定済みセルの画像は再取得しない。画面非表示では停止、復帰時再取得。Runs一覧の進捗更新は表示中のqueued/runningだけ、同時要求上限4。
- complete/partial/failed到達時は最終取得後停止。deletingは削除確認だけ継続し404で停止。詳細の予期しない404は不在表示で停止。401/403、未知Version、ページ離脱、Plugin無効化も停止する。429/503/ネットワーク障害はRetry-Afterと5/10/20/40/60秒のbackoffを用い、連続5回失敗で自動更新を止め「再取得」を表示。復帰操作まで無限pollしない。通信タイムアウトは各30秒、Runの期限を延長しない。
- 独立JS workspace `apps/admin`を後続で追加し、`@wordpress/scripts`で`src/index.tsx`から`build/index.js`と`index.asset.php`を生成する。WordPress提供React/wp-element・components・api-fetch・i18n等は依存抽出してPHPで登録、Reactを別bundleで重複同梱しない。PHPはODVRページだけenqueueする。sharedはBrowserで使える型/既定値だけ参照し、Runner/Playwright/Node APIを取り込まない。
- 現在のroot buildはshared/runnerのみ。後続でadmin build/lintとZIP検証をCIへ追加し、Plugin配布時にbuild成果物・asset依存情報・翻訳・Schemaを含めnode_modules/srcの開発用成果物を除外する。今回依存更新は行わない。WP 6.7/PHP 7.4を維持し、採用時の@wordpress/scriptsとその生成依存がWP 6.7で使えるか実機確認する。

## API補足・リスク・未決事項

本書の追加提案は先行契約が実装済みであることを意味しない。採用後、管理API実装Issueに以下のフィールドをSchema/fixtureとともに定義する。既存ルートの責務は維持し、必要値がない場合に画面が推測して破壊操作を許可しない。

| 項目 | 方針・後続で確定する範囲 |
|---|---|
| 管理応答の表示フィールド | SuiteのTarget/Device数・latest_run、Runの固定表示情報/画像有無・保護理由/参照元、Device409の利用Suite、Settingsの保持件数/容量・削除失敗。集約フィールドなしでは本文記載の追加GET/ページング、削除保護はサーバーに委ねる。容量/保護一覧は未対応表示にする |
| 接続確認 | 管理API/Dispatcher専用診断ルートは追加設計。本PR採用後に#4/Phase 5の実装契約へ反映。#10は独立設計であり、そのPR採用状況も確認する。Job起動を診断の代替にしない |
| 保存・編集競合 | Suite/Targetの複数要求は原子的でない。POST応答喪失・複数タブ編集に完全な重複防止/競合検知はない。Runの設定固定はサーバーで保証、一括保存/ETag導入は別設計 |
| Baseline互換性・エラー | 詳細fingerprint/Version互換性は#6、Runnerと製品Uploadのエラーコード対応はAPI実装で確定。画面は返された理由を表示し、独自に互換扱いしない |
| Storage・秘密・SSRF | 非公開Storage/Web/CDN設定、BearerとBasic経路、DNS接続固定の製品実装が結合テストの前提。画面だけでホスト全経路の保護は保証しない。設定不備では実行停止 |
| 大画像・参照連鎖 | 1セルでも数千万pixelsのdecodeには端末メモリ限界がある。参照閉包でLast Nを超える容量が残る。実機メモリ/操作性を測定し、必要なら別途縮小画像APIを設計する |
| build・運用期限 | WP 6.7での依存/翻訳、Cloud Run・Secret TTLと任意期限設定の整合は実装時に検証。WP-Cronの遅延は画面GETで期限確認するが、確実なcleanupには外部Cron等が必要 |

## 変更予定ファイル・5項目の実装順序

以下は設計採用後の実装Issue分割案。PHPパスは`wordpress/od-visual-regression/`を基準とする。#2/#4の設計は採用済みだが製品実装は未完了。共通契約とStorage/Runnerの実装完了後に結合テストを行う。今回実装Issueは追加しない。

| 順序 | 画面・主な予定ファイル | 必要な検証 |
|---|---|---|
| 1 | 管理メニュー/権限/共通API client/build。`includes/class-odvr-admin.php`、`class-odvr-plugin.php`、`apps/admin/{package.json,src/index.tsx,src/api/client.ts,src/components/*}`、root `package.json`、`.github/workflows/`、Plugin build/翻訳手順 | 4メニュー、直アクセス/権限/Cookie/nonce、WP 6.7/PHP 7.4、依存抽出/React重複なし、ODVR限定enqueue、ZIPとJS翻訳 |
| 2 | Devices/Settings/診断。`apps/admin/src/pages/{devices,settings}/*`、`includes/class-odvr-{device,settings}-controller.php`、Dispatcher診断ルート、設定契約fixture | 全プリセット/入力境界、slug固定/参照中409、既定値コピー、秘密非表示/秘密PATCH400、任意URL拒否、署名/Storage診断、診断によるRun/Job作成0 |
| 3 | Tests編集/Target/Baseline選択/Run開始。`apps/admin/src/pages/tests/*`、`includes/class-odvr-{suite,target,run}-controller.php`、契約のSuite表示フィールド | 公開CPT/検索/ページング/Custom URL、1×1/100×10、削除済み投稿、保存途中失敗/POST喪失、Baseline全方式、同Suite二重実行409、202から詳細遷移 |
| 4 | Runs詳細/進捗/Environment/昇格/削除/Retention表示。`apps/admin/src/pages/runs/*`、`src/hooks/use-run-polling.ts`、Run/Settings応答fixture | 再送で件数不変、100%running、partial/failed、期限/認証/backoff/終端poll停止、環境追加/削除/null、Baseline全画像検査、参照閉包/保護競合、deleting失敗/404 |
| 5 | Viewer/Blob/Overlay/アクセシビリティと結合。`apps/admin/src/components/viewer/*`、`src/hooks/use-snapshot-image.ts`、`apps/admin/tests/*`、`docs/development.md` | 4モード、Sliderキー操作、異寸法/透明領域、比較なし/失敗/401/403/404/503/decode失敗、切替競合/abort/revoke、大画像/メモリ、翻訳/キーボード/フォーカス、Suite作成→更新前Run→Pinned→更新後Run→比較→昇格 |

## 受け入れ条件・今回の検証

| Issue #11の条件 | 本書の対応 |
|---|---|
| 全4メニュー・Suite編集・Run実行・確認フロー | 管理者フロー、Tests、Runs、Devices、Settings |
| 画面操作と確定した管理APIの対応 | 管理API対応表。未定義の横断Runsは作らず、診断と表示フィールドの追加提案を分離 |
| 4 Viewerモード・画像寸法/欠損/認証エラー | Viewerのモード表/取得状態表、共通寸法、Blob管理 |
| Environment・進捗・Baseline・Retention | Runs詳細、Baseline設定/固定参照、削除/参照閉包と保護理由 |
| 秘密・権限・アクセシビリティ | config専用秘密、Cookie/nonce/manage_odvr、翻訳/フォーカス/キーボード、poll停止 |
| 5項目以内の画面別実装順 | 上記5段階表の対象ファイルと検証 |

今回は指定仕様、Pluginの直接参照先、shared/Device/Prototype Schema、現行比較処理、先行DB/API設計、content API契約を照合した。Markdownの相対リンク、入力範囲/既定値/判定境界、APIのmethod/path、docs限定の変更と空白を確認する。設計のみのため製品全テスト/buildは行わず、画面・DB・認証・クラウド動作を実機検証済みとはしない。

Firefox、WordPressログイン後撮影、通知、AI解析はPhase 2候補として対象外。無関係な改修・依存更新・全体整形は行わない。
