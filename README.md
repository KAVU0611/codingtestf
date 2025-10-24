# Playlist Viewer

PHP 製のシンプルなプレイリスト閲覧アプリです。プレイリスト用のフォルダに置かれた TSV 形式のアルバムファイルを読み込み、曲の一覧表示・フィルタリング・並べ替え、さらに新規アルバムの追加までブラウザ上から完結します。

## 動作要件

- PHP 8.1 以上（ビルトインサーバーで十分です）
- ブラウザ（最新の Chromium / Firefox / Safari / Edge で動作確認済み）

## セットアップ

```bash
git clone https://github.com/KAVU0611/codingtestf.git
cd codingtestf
php -S localhost:8000 -t public
```

ブラウザで http://localhost:8000 を開きます。

### プレイリストフォルダの指定

トップ画面の「Playlist Folder」に、アルバム TSV が並んでいるフォルダのパスを入力します。Windows のパス（例: `C:\Users\kavu1\Downloads\codingtest\codingtest\playlist\playlist`）もサポートされており、WSL 内では `/mnt/c/...` に自動変換されます。  
一度設定するとブラウザのセッションに保持されるので、リロード後も同じフォルダが使われます。

環境変数 `PLAYLIST_ROOT` を起動前に指定しておくことも可能です:

```bash
export PLAYLIST_ROOT=/mnt/c/Users/kavu1/Downloads/codingtest/codingtest/playlist/playlist
php -S localhost:8000 -t public
```

## 使い方

- **Playlist Folder**: 読み込み元のフォルダを指定します。サブフォルダごとにプレイリストとして認識され、フォルダ直下の `.tsv` は「__root__」扱いで選択できます。
- **Add Album**: アルバム名と `タイトル<TAB>アーティスト<TAB>演奏時間` 形式の行を入力して「Save album」を押すと、新しい TSV ファイルが現在選択中のプレイリストに保存されます。
- **Playlist 一覧**:
  - `Playlist folder` セレクタでアルバムフォルダを切り替え
  - `Album` / `Artist` は部分一致、`Title prefix` は接頭辞一致で絞り込み
  - `Sort` で標準の「アルバム名＋曲順」または「演奏時間（短→長）」を選択
  - 一覧下部に、対象曲数と合計時間を表示

## 仕様チェックリスト

- ✅ プレイリスト（フォルダ）内にある TSV アルバムの曲を一覧表示
- ✅ 標準の並び順は「アルバム名 → 演奏順」
- ✅ 演奏時間によるソートを選択可能
- ✅ アルバム名・アーティスト名で絞り込み可能
- ✅ 曲名の接頭辞（例: 「A」から始まる曲）で絞り込み可能
- ✅ アプリから TSV アルバムの新規作成が可能

## テスト

コードの静的チェックとして PHP の構文チェックを行います:

```bash
for file in src/*.php public/index.php; do
    php -l "$file"
done
```

## ライセンス

このリポジトリ内のコードは MIT ライセンスとします。
