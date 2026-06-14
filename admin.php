<?php
require __DIR__ . '/lib/auth.php';
require_login();
$csrfToken = csrf_token();
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Lightfolio | 管理后台</title>
    <link rel="stylesheet" href="./styles.css?v=20260614-admin-preview" />
  </head>
  <body class="admin-page">
    <div id="admin-app" v-cloak>
      <header class="admin-topbar">
        <a class="brand" href="./index.html" aria-label="返回画廊">
          <span class="brand-mark"></span>
          <span>Lightfolio</span>
        </a>
        <div class="admin-toolbar">
          <button class="ghost-button" type="button" @click="exportPhotos">导出</button>
          <label class="ghost-button file-action">
            导入
            <input type="file" accept="application/json" @change="importPhotos" />
          </label>
          <a class="ghost-button" href="./logout.php">退出</a>
          <a class="solid-button" href="./index.html">查看画廊</a>
        </div>
      </header>

      <main class="admin-console">
        <aside class="admin-rail">
          <section class="status-card">
            <span>状态</span>
            <strong>{{ saveStatus }}</strong>
          </section>
          <section class="metric-grid" aria-label="数据统计">
            <div>
              <strong>{{ categories.length }}</strong>
              <span>分类</span>
            </div>
            <div>
              <strong>{{ groups.length }}</strong>
              <span>分组</span>
            </div>
            <div>
              <strong>{{ photos.length }}</strong>
              <span>照片</span>
            </div>
          </section>
        </aside>

        <section class="admin-workspace">
          <section class="manager-panel photo-manager" aria-labelledby="photo-title">
            <div class="panel-heading">
              <div>
                <span class="panel-kicker">Photos</span>
                <h1 id="photo-title">作品管理</h1>
              </div>
              <button class="ghost-button" type="button" @click="resetPhotoForm">新建作品</button>
            </div>

            <form class="photo-form" @submit.prevent="submitPhoto">
              <label>
                <span>作品标题</span>
                <input v-model.trim="photoForm.title" type="text" required maxlength="40" placeholder="例如：雨后街角" />
              </label>

              <label>
                <span>所属分组</span>
                <select v-model="photoForm.group" required>
                  <option v-for="group in groups" :key="group.id" :value="group.id">
                    {{ group.name }} / {{ categoryLabel(group.category) }}
                  </option>
                </select>
              </label>

              <label>
                <span>拍摄信息</span>
                <input v-model.trim="photoForm.meta" type="text" maxlength="80" placeholder="例如：上海 · 35mm" />
              </label>

              <label>
                <span>图片地址</span>
                <input v-model.trim="photoForm.url" type="text" required placeholder="上传后自动生成，也可填写 https://..." />
              </label>

              <label class="upload-dropzone">
                <span>{{ uploadLabel }}</span>
                <small>选择图片后会在浏览器端压缩为 WEBP 再上传</small>
                <input type="file" accept="image/*" @change="uploadPhoto" />
              </label>

              <div class="photo-preview">
                <img v-if="formPreviewSrc()" :src="formPreviewSrc()" alt="预览" />
                <span v-else>预览</span>
              </div>

              <div class="form-actions">
                <button class="solid-button" type="submit">保存作品</button>
                <button class="ghost-button" type="button" @click="resetPhotoForm">清空</button>
              </div>
            </form>
          </section>

          <section class="manager-grid">
            <section class="manager-panel" aria-labelledby="category-title">
              <div class="panel-heading">
                <div>
                  <span class="panel-kicker">Categories</span>
                  <h2 id="category-title">分类</h2>
                </div>
              </div>

              <form class="compact-form" @submit.prevent="submitCategory">
                <label>
                  <span>分类标识</span>
                  <input v-model.trim="categoryForm.id" :disabled="Boolean(categoryForm.originalId)" type="text" maxlength="40" placeholder="portrait" required />
                </label>
                <label>
                  <span>分类名称</span>
                  <input v-model.trim="categoryForm.name" type="text" maxlength="24" placeholder="人像" required />
                </label>
                <div class="form-actions">
                  <button class="solid-button" type="submit">保存分类</button>
                  <button class="ghost-button" type="button" @click="resetCategoryForm">新建</button>
                </div>
              </form>

              <div class="token-list">
                <article v-for="category in categories" :key="category.id" class="token-item">
                  <div>
                    <strong>{{ category.name }}</strong>
                    <small>{{ category.id }} · {{ groups.filter((group) => group.category === category.id).length }} 组</small>
                  </div>
                  <div class="row-actions">
                    <button type="button" @click="editCategory(category.id)">编辑</button>
                    <button class="is-danger" type="button" @click="deleteCategory(category.id)">删除</button>
                  </div>
                </article>
              </div>
            </section>

            <section class="manager-panel" aria-labelledby="group-title">
              <div class="panel-heading">
                <div>
                  <span class="panel-kicker">Collections</span>
                  <h2 id="group-title">分组</h2>
                </div>
              </div>

              <form class="compact-form" @submit.prevent="submitGroup">
                <label>
                  <span>分组标识</span>
                  <input v-model.trim="groupForm.id" :disabled="Boolean(groupForm.originalId)" type="text" maxlength="40" placeholder="wedding-2026" required />
                </label>
                <label>
                  <span>所属分类</span>
                  <select v-model="groupForm.category" required>
                    <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                  </select>
                </label>
                <label>
                  <span>分组名称</span>
                  <input v-model.trim="groupForm.name" type="text" maxlength="24" placeholder="婚礼纪实" required />
                </label>
                <label>
                  <span>分组说明</span>
                  <input v-model.trim="groupForm.description" type="text" maxlength="80" placeholder="用于前台分组卡片" />
                </label>
                <div class="form-actions">
                  <button class="solid-button" type="submit">保存分组</button>
                  <button class="ghost-button" type="button" @click="resetGroupForm">新建</button>
                </div>
              </form>

              <div class="token-list">
                <article v-for="group in groups" :key="group.id" class="token-item">
                  <div>
                    <strong>{{ group.name }}</strong>
                    <small>{{ categoryLabel(group.category) }} · {{ photos.filter((photo) => photo.group === group.id).length }} 张<span v-if="group.description"> · {{ group.description }}</span></small>
                  </div>
                  <div class="row-actions">
                    <button type="button" @click="editGroup(group.id)">编辑</button>
                    <button class="is-danger" type="button" @click="deleteGroup(group.id)">删除</button>
                  </div>
                </article>
              </div>
            </section>
          </section>

          <section class="manager-panel library-panel" aria-labelledby="library-title">
            <div class="panel-heading">
              <div>
                <span class="panel-kicker">Library</span>
                <h2 id="library-title">作品库</h2>
              </div>
              <span class="panel-count">{{ photos.length }} 张</span>
            </div>

            <div class="library-grid">
              <article v-for="photo in photos" :key="photo.id" class="library-item">
                <img :src="previewSrc(photo)" :alt="photo.title" loading="lazy" />
                <div>
                  <h3>{{ photo.title }}</h3>
                  <p>{{ groupLabel(photo.group) }} / {{ categoryLabel(groupMap[photo.group]?.category) }}<span v-if="photo.meta"> · {{ photo.meta }}</span></p>
                </div>
                <div class="row-actions">
                  <button type="button" @click="editPhoto(photo.id)">编辑</button>
                  <button class="is-danger" type="button" @click="deletePhoto(photo.id)">删除</button>
                </div>
              </article>
            </div>
          </section>
        </section>
      </main>
    </div>

    <script>
      window.LightfolioConfig = {
        csrfToken: "<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>",
      };
    </script>
    <script src="./vendor/vue.global.prod.js"></script>
    <script src="./gallery-data.js?v=20260614-preview-fix"></script>
    <script src="./admin.js?v=20260614-preview-fix"></script>
  </body>
</html>
