const { createApp } = Vue;

createApp({
  data() {
    return {
      photos: [],
      groups: [],
      categories: [],
      saveStatus: "已准备",
      uploadLabel: "上传照片",
      photoForm: { id: "", title: "", group: "", meta: "", url: "", previewUrl: "" },
      categoryForm: { originalId: "", id: "", name: "" },
      groupForm: { originalId: "", id: "", category: "", name: "", description: "" },
    };
  },
  computed: {
    categoryMap() {
      return window.LightfolioStore.getCategoryMap(this.categories);
    },
    groupMap() {
      return window.LightfolioStore.getGroupMap(this.groups);
    },
  },
  async mounted() {
    this.categories = await window.LightfolioStore.loadCategories();
    this.groups = await window.LightfolioStore.loadGroups();
    this.photos = await window.LightfolioStore.loadPhotos();
    this.resetPhotoForm();
    this.resetCategoryForm();
    this.resetGroupForm();
  },
  methods: {
    emptyPhotoForm() {
      return { id: "", title: "", group: "", meta: "", url: "", previewUrl: "" };
    },
    emptyCategoryForm() {
      return { originalId: "", id: "", name: "" };
    },
    emptyGroupForm() {
      return { originalId: "", id: "", category: "", name: "", description: "" };
    },
    categoryLabel(categoryId) {
      return this.categoryMap[categoryId]?.name || categoryId || "";
    },
    groupLabel(groupId) {
      return this.groupMap[groupId]?.name || groupId || "";
    },
    previewSrc(photo) {
      return photo?.previewUrl || photo?.thumbnail || photo?.url || "";
    },
    formPreviewSrc() {
      return this.photoForm.previewUrl || this.photoForm.url || "";
    },
    fallbackCategoryId() {
      return this.categories[0]?.id || "portfolio";
    },
    fallbackGroupId() {
      return this.groups[0]?.id || "daily";
    },
    normalizeId(value) {
      return window.LightfolioStore.normalizeId(value);
    },
    resetPhotoForm() {
      this.photoForm = this.emptyPhotoForm();
      this.photoForm.group = this.fallbackGroupId();
      this.uploadLabel = "上传照片";
    },
    resetCategoryForm() {
      this.categoryForm = this.emptyCategoryForm();
    },
    resetGroupForm() {
      this.groupForm = this.emptyGroupForm();
      this.groupForm.category = this.fallbackCategoryId();
    },
    async saveCategories() {
      const result = await window.LightfolioStore.saveCategories(this.categories, { requireServer: true });
      this.categories = result.categories;
      this.saveStatus = "分类已保存";
    },
    async saveGroups() {
      const result = await window.LightfolioStore.saveGroups(this.groups, { requireServer: true });
      this.groups = result.groups;
      this.saveStatus = "分组已保存";
    },
    async savePhotos() {
      const result = await window.LightfolioStore.savePhotos(this.photos, { requireServer: true });
      this.photos = result.photos;
      this.saveStatus = "作品已保存";
    },
    async submitPhoto() {
      const photo = {
        id: this.photoForm.id || crypto.randomUUID(),
        title: this.photoForm.title.trim(),
        group: this.photoForm.group || this.fallbackGroupId(),
        meta: this.photoForm.meta.trim(),
        url: this.photoForm.url.trim(),
        previewUrl: this.photoForm.previewUrl || this.photoForm.url.trim(),
      };

      const index = this.photos.findIndex((item) => item.id === photo.id);
      if (index >= 0) this.photos[index] = photo;
      else this.photos.unshift(photo);

      try {
        await this.savePhotos();
        this.resetPhotoForm();
      } catch {
        this.saveStatus = "保存失败，请重新登录";
      }
    },
    editPhoto(id) {
      const photo = this.photos.find((item) => item.id === id);
      if (!photo) return;
      this.photoForm = { ...photo };
    },
    async deletePhoto(id) {
      this.photos = this.photos.filter((item) => item.id !== id);
      try {
        await this.savePhotos();
        if (this.photoForm.id === id) this.resetPhotoForm();
      } catch {
        this.saveStatus = "删除失败";
      }
    },
    async submitCategory() {
      const originalId = this.categoryForm.originalId;
      const id = originalId || this.normalizeId(this.categoryForm.id);
      const name = this.categoryForm.name.trim();

      if (!id || !name) {
        this.saveStatus = "请填写分类信息";
        return;
      }

      const exists = this.categories.some((category) => category.id === id && category.id !== originalId);
      if (exists) {
        this.saveStatus = "分类标识已存在";
        return;
      }

      if (originalId) this.categories = this.categories.map((category) => (category.id === originalId ? { id, name } : category));
      else this.categories.push({ id, name });

      try {
        await this.saveCategories();
        this.resetCategoryForm();
      } catch {
        this.saveStatus = "分类保存失败";
      }
    },
    editCategory(id) {
      const category = this.categories.find((item) => item.id === id);
      if (!category) return;
      this.categoryForm = { originalId: category.id, id: category.id, name: category.name };
    },
    async deleteCategory(id) {
      if (this.categories.length <= 1) {
        this.saveStatus = "至少保留一个分类";
        return;
      }
      const nextCategory = this.categories.find((category) => category.id !== id)?.id;
      this.categories = this.categories.filter((category) => category.id !== id);
      this.groups = this.groups.map((group) => (group.category === id ? { ...group, category: nextCategory } : group));
      try {
        await this.saveCategories();
        await this.saveGroups();
        this.resetCategoryForm();
      } catch {
        this.saveStatus = "删除分类失败";
      }
    },
    async submitGroup() {
      const originalId = this.groupForm.originalId;
      const id = originalId || this.normalizeId(this.groupForm.id);
      const category = this.groupForm.category || this.fallbackCategoryId();
      const name = this.groupForm.name.trim();
      const description = this.groupForm.description.trim();

      if (!id || !name) {
        this.saveStatus = "请填写分组信息";
        return;
      }

      const exists = this.groups.some((group) => group.id === id && group.id !== originalId);
      if (exists) {
        this.saveStatus = "分组标识已存在";
        return;
      }

      const group = { id, name, category, description };
      if (originalId) this.groups = this.groups.map((item) => (item.id === originalId ? group : item));
      else this.groups.push(group);

      try {
        await this.saveGroups();
        this.resetGroupForm();
      } catch {
        this.saveStatus = "分组保存失败";
      }
    },
    editGroup(id) {
      const group = this.groups.find((item) => item.id === id);
      if (!group) return;
      this.groupForm = { originalId: group.id, ...group };
    },
    async deleteGroup(id) {
      if (this.groups.length <= 1) {
        this.saveStatus = "至少保留一个分组";
        return;
      }
      const nextGroup = this.groups.find((group) => group.id !== id)?.id;
      this.groups = this.groups.filter((group) => group.id !== id);
      this.photos = this.photos.map((photo) => (photo.group === id ? { ...photo, group: nextGroup } : photo));
      try {
        await this.saveGroups();
        await this.savePhotos();
        this.resetGroupForm();
      } catch {
        this.saveStatus = "删除分组失败";
      }
    },
    async imageFileToWebp(file, quality = 0.82) {
      if (!file.type.startsWith("image/")) {
        throw new Error("请选择图片文件");
      }

      const bitmap = await this.decodeImage(file);
      const full = await this.renderWebp(bitmap, bitmap.width, bitmap.height, quality);
      const previewSize = this.fitInside(bitmap.width, bitmap.height, 900);
      const preview = await this.renderWebp(bitmap, previewSize.width, previewSize.height, 0.72);
      if (typeof bitmap.close === "function") bitmap.close();

      return {
        file: new File([full.blob], this.webpFilename(file.name), { type: "image/webp" }),
        previewFile: new File([preview.blob], this.webpFilename(file.name, "preview"), { type: "image/webp" }),
        width: full.width,
        height: full.height,
        previewWidth: preview.width,
        previewHeight: preview.height,
        originalSize: file.size,
        webpSize: full.blob.size,
        previewSize: preview.blob.size,
      };
    },
    async renderWebp(source, width, height, quality) {
      const canvas = document.createElement("canvas");
      canvas.width = width;
      canvas.height = height;

      const context = canvas.getContext("2d", { alpha: true });
      if (!context) throw new Error("当前浏览器无法压缩图片");

      context.drawImage(source, 0, 0, width, height);
      const blob = await this.canvasToBlob(canvas, "image/webp", quality);
      if (blob.type !== "image/webp") throw new Error("当前浏览器不支持导出 WebP");

      return { blob, width, height };
    },
    fitInside(width, height, maxSide) {
      const scale = Math.min(1, maxSide / Math.max(width, height));
      return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
      };
    },
    async decodeImage(file) {
      if (window.createImageBitmap) {
        try {
          return await createImageBitmap(file, { imageOrientation: "from-image" });
        } catch {
          return await createImageBitmap(file);
        }
      }

      return new Promise((resolve, reject) => {
        const image = new Image();
        const url = URL.createObjectURL(file);
        image.onload = () => {
          URL.revokeObjectURL(url);
          resolve(image);
        };
        image.onerror = () => {
          URL.revokeObjectURL(url);
          reject(new Error("图片解码失败"));
        };
        image.src = url;
      });
    },
    canvasToBlob(canvas, type, quality) {
      return new Promise((resolve, reject) => {
        canvas.toBlob(
          (blob) => {
            if (blob) resolve(blob);
            else reject(new Error("图片压缩失败"));
          },
          type,
          quality,
        );
      });
    },
    webpFilename(name, suffix = "") {
      const baseName = String(name || "photo")
        .replace(/\.[^.]+$/, "")
        .replace(/[^a-zA-Z0-9_-]+/g, "-")
        .replace(/^[-_]+|[-_]+$/g, "")
        .slice(0, 50);

      return `${baseName || "photo"}${suffix ? `-${suffix}` : ""}.webp`;
    },
    formatBytes(bytes) {
      if (!Number.isFinite(bytes) || bytes <= 0) return "0 KB";
      if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
      return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    },
    async uploadPhoto(event) {
      const file = event.target.files?.[0];
      if (!file) return;

      this.uploadLabel = "正在压缩为 WebP...";
      this.saveStatus = "正在压缩图片...";

      try {
        const compressed = await this.imageFileToWebp(file);
        const formData = new FormData();
        formData.append("photo", compressed.file);
        formData.append("preview", compressed.previewFile);
        this.uploadLabel = `正在上传 WebP · ${this.formatBytes(compressed.webpSize)}`;
        this.saveStatus = `已压缩 ${this.formatBytes(compressed.originalSize)} → ${this.formatBytes(compressed.webpSize)}，预览图 ${this.formatBytes(compressed.previewSize)}`;

        const response = await fetch("./api/upload.php", {
          method: "POST",
          headers: { "X-CSRF-Token": window.LightfolioStore.csrfToken() },
          body: formData,
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.error || "Upload failed");
        this.photoForm.url = payload.url;
        this.photoForm.previewUrl = payload.previewUrl || payload.url;
        this.uploadLabel = `已上传 WebP · ${payload.width}×${payload.height}`;
        this.saveStatus = `图片已压缩并上传，请保存作品 · 预览图 ${this.formatBytes(compressed.previewSize)}`;
      } catch (error) {
        this.uploadLabel = "上传失败，请重试";
        this.saveStatus = error.message || "图片上传失败";
      } finally {
        event.target.value = "";
      }
    },
    exportPhotos() {
      const blob = new Blob([JSON.stringify(this.photos, null, 2)], { type: "application/json" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = "lightfolio-photos.json";
      link.click();
      URL.revokeObjectURL(url);
    },
    importPhotos(event) {
      const file = event.target.files?.[0];
      if (!file) return;
      const reader = new FileReader();
      reader.addEventListener("load", async () => {
        try {
          this.photos = window.LightfolioStore.normalizePhotos(JSON.parse(reader.result));
          await this.savePhotos();
          this.resetPhotoForm();
        } catch {
          this.saveStatus = "导入失败";
        }
      });
      reader.readAsText(file);
      event.target.value = "";
    },
  },
}).mount("#admin-app");
