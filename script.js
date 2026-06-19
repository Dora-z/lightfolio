const { createApp } = Vue;

createApp({
  data() {
    return {
      photos: [],
      groups: [],
      categories: [],
      activeCategory: "all",
      compact: false,
      switching: false,
      viewerOpen: false,
      viewerChanging: false,
      viewerPhotos: [],
      currentIndex: 0,
      switchTimer: null,
      photoRatios: {},
      viewerZoom: {
        scale: 1,
        originX: 50,
        originY: 50,
        x: 0,
        y: 0,
      },
      viewerDrag: {
        active: false,
        startX: 0,
        startY: 0,
        baseX: 0,
        baseY: 0,
      },
    };
  },
  computed: {
    categoryMap() {
      return window.LightfolioStore.getCategoryMap(this.categories);
    },
    groupMap() {
      return window.LightfolioStore.getGroupMap(this.groups);
    },
    visibleGroups() {
      return this.groups.filter((group) => {
        const hasPhotos = this.photos.some((photo) => photo.group === group.id);
        return hasPhotos && (this.activeCategory === "all" || group.category === this.activeCategory);
      });
    },
    visibleCategories() {
      const ids = new Set(this.visibleGroups.map((group) => group.category));
      if (this.activeCategory !== "all") {
        return this.categories.filter((category) => category.id === this.activeCategory && ids.has(category.id));
      }
      return this.categories.filter((category) => ids.has(category.id));
    },
    totalPhotoCount() {
      const visibleGroupIds = new Set(this.groups.filter((group) => this.photosForGroup(group.id).length > 0).map((group) => group.id));
      return this.photos.filter((photo) => visibleGroupIds.has(photo.group)).length;
    },
    galleryStats() {
      const visibleGroupIds = new Set(this.visibleGroups.map((group) => group.id));
      return {
        groups: this.visibleGroups.length,
        photos: this.photos.filter((photo) => visibleGroupIds.has(photo.group)).length,
      };
    },
    activeGalleryTitle() {
      return this.activeCategory === "all" ? "全部作品" : this.categoryLabel(this.activeCategory);
    },
    activeGalleryKicker() {
      return this.activeCategory === "all" ? "Lightfolio" : "当前分类";
    },
    currentPhoto() {
      return this.viewerPhotos[this.currentIndex] || null;
    },
    viewerPhotoDetails() {
      if (!this.currentPhoto) return "";

      const info = window.LightfolioStore.getPhotoInfo(this.currentPhoto);
      return [info.shotAt, info.gear, info.focalLength, info.aperture, info.shutter, info.iso].filter(Boolean).join(" · ");
    },
    viewerGroupPosition() {
      if (!this.currentPhoto) return "";
      return [this.groupLabel(this.currentPhoto.group), `${this.currentIndex + 1} / ${this.viewerPhotos.length}`].filter(Boolean).join(" · ");
    },
    viewerExtraMeta() {
      if (!this.currentPhoto) return "";
      const info = window.LightfolioStore.getPhotoInfo(this.currentPhoto);
      return info.meta;
    },
    viewerMetaLines() {
      if (!this.currentPhoto) return [];

      const info = window.LightfolioStore.getPhotoInfo(this.currentPhoto);
      return [
        [this.groupLabel(this.currentPhoto.group), `${this.currentIndex + 1} / ${this.viewerPhotos.length}`].filter(Boolean).join(" · "),
        [info.shotAt, info.gear, info.focalLength, info.aperture, info.shutter, info.iso].filter(Boolean).join(" · "),
        info.meta,
      ].filter(Boolean);
    },
    viewerImageStyle() {
      return {
        transform: `translate3d(${this.viewerZoom.x}px, ${this.viewerZoom.y}px, 0) scale(${this.viewerZoom.scale})`,
        transformOrigin: `${this.viewerZoom.originX}% ${this.viewerZoom.originY}%`,
      };
    },
  },
  async mounted() {
    this.categories = await window.LightfolioStore.loadCategories();
    this.groups = await window.LightfolioStore.loadGroups();
    this.photos = await window.LightfolioStore.loadPhotos();

    document.addEventListener("keydown", this.onKeydown);
    document.addEventListener("contextmenu", this.preventImageMenu);
    document.addEventListener("dragstart", this.preventImageDrag);
    document.addEventListener("copy", this.preventImageCopy);
  },
  beforeUnmount() {
    document.removeEventListener("keydown", this.onKeydown);
    document.removeEventListener("contextmenu", this.preventImageMenu);
    document.removeEventListener("dragstart", this.preventImageDrag);
    document.removeEventListener("copy", this.preventImageCopy);
  },
  methods: {
    categoryLabel(categoryId) {
      return this.categoryMap[categoryId]?.name || categoryId || "";
    },
    groupLabel(groupId) {
      return this.groupMap[groupId]?.name || groupId || "";
    },
    photosForGroup(groupId) {
      return this.photos.filter((photo) => photo.group === groupId);
    },
    coverPhotosForGroup(group) {
      const photos = this.photosForGroup(group.id);
      if (!group.coverPhotoId) return photos;

      const cover = photos.find((photo) => photo.id === group.coverPhotoId);
      if (!cover) return photos;

      return [cover, ...photos.filter((photo) => photo.id !== cover.id)];
    },
    previewSrc(photo) {
      return photo?.previewUrl || photo?.thumbnail || photo?.url || "";
    },
    groupsForCategory(categoryId) {
      return this.visibleGroups.filter((group) => group.category === categoryId);
    },
    categoryPhotoCount(categoryId) {
      const groupIds = new Set(this.groups.filter((group) => group.category === categoryId).map((group) => group.id));
      return this.photos.filter((photo) => groupIds.has(photo.group)).length;
    },
    stackClass(groupId) {
      const photos = this.coverPhotosForGroup(this.groupMap[groupId] || { id: groupId }).slice(0, 4);
      const countClass = `has-${Math.min(photos.length, 4)}`;
      const orientation = this.groupOrientation(photos);
      return [countClass, `is-${orientation}`];
    },
    groupOrientation(photos) {
      const ratios = photos.map((photo) => this.photoRatios[photo.id]).filter(Boolean);
      if (!ratios.length) return "landscape";

      const landscape = ratios.filter((ratio) => ratio >= 1.12).length;
      const portrait = ratios.filter((ratio) => ratio <= 0.9).length;

      if (landscape >= Math.max(1, portrait + 1)) return "landscape";
      if (portrait >= Math.max(1, landscape + 1)) return "portrait";
      return "mixed";
    },
    rememberPhotoSize(photoId, event) {
      const image = event.target;
      if (!image?.naturalWidth || !image?.naturalHeight) return;

      this.photoRatios = {
        ...this.photoRatios,
        [photoId]: image.naturalWidth / image.naturalHeight,
      };
    },
    stackMeta(group) {
      return [this.categoryLabel(group.category), `${this.photosForGroup(group.id).length} 张`, group.description]
        .filter(Boolean)
        .join(" · ");
    },
    setCategory(categoryId) {
      this.switching = true;
      window.clearTimeout(this.switchTimer);
      this.switchTimer = window.setTimeout(() => {
        this.activeCategory = categoryId;
        requestAnimationFrame(() => {
          this.switching = false;
        });
      }, 150);
    },
    openGroup(groupId) {
      this.viewerPhotos = this.coverPhotosForGroup(this.groupMap[groupId] || { id: groupId });
      this.currentIndex = 0;
      this.viewerOpen = true;
      this.viewerChanging = true;
      this.resetViewerZoom();
      document.body.classList.add("is-locked");
    },
    closeViewer() {
      this.viewerOpen = false;
      this.viewerPhotos = [];
      this.resetViewerZoom();
      document.body.classList.remove("is-locked");
    },
    moveViewer(step) {
      if (!this.viewerPhotos.length) return;
      this.viewerChanging = true;
      this.resetViewerZoom();
      window.setTimeout(() => {
        this.currentIndex = (this.currentIndex + step + this.viewerPhotos.length) % this.viewerPhotos.length;
      }, 130);
    },
    setViewerIndex(index) {
      if (index === this.currentIndex) return;
      this.viewerChanging = true;
      this.resetViewerZoom();
      window.setTimeout(() => {
        this.currentIndex = index;
      }, 130);
    },
    onViewerImageLoad() {
      this.viewerChanging = false;
    },
    zoomViewer(event) {
      if (!this.currentPhoto || this.viewerChanging) return;

      const rect = event.currentTarget.getBoundingClientRect();
      const originX = ((event.clientX - rect.left) / rect.width) * 100;
      const originY = ((event.clientY - rect.top) / rect.height) * 100;
      const direction = event.deltaY < 0 ? 1 : -1;
      const nextScale = this.clamp(this.viewerZoom.scale + direction * 0.22, 1, 4);

      this.viewerZoom = {
        scale: nextScale,
        originX: this.clamp(originX, 0, 100),
        originY: this.clamp(originY, 0, 100),
        x: nextScale === 1 ? 0 : this.viewerZoom.x,
        y: nextScale === 1 ? 0 : this.viewerZoom.y,
      };
    },
    startViewerPan(event) {
      if (this.viewerZoom.scale <= 1 || this.viewerChanging) return;

      this.viewerDrag = {
        active: true,
        startX: event.clientX,
        startY: event.clientY,
        baseX: this.viewerZoom.x,
        baseY: this.viewerZoom.y,
      };

      event.currentTarget.setPointerCapture?.(event.pointerId);
    },
    moveViewerPan(event) {
      if (!this.viewerDrag.active) return;

      const nextX = this.viewerDrag.baseX + event.clientX - this.viewerDrag.startX;
      const nextY = this.viewerDrag.baseY + event.clientY - this.viewerDrag.startY;

      this.viewerZoom = {
        ...this.viewerZoom,
        x: nextX,
        y: nextY,
      };
    },
    endViewerPan(event) {
      if (!this.viewerDrag.active) return;
      event.currentTarget.releasePointerCapture?.(event.pointerId);
      this.viewerDrag = {
        active: false,
        startX: 0,
        startY: 0,
        baseX: 0,
        baseY: 0,
      };
    },
    resetViewerZoom() {
      this.viewerZoom = {
        scale: 1,
        originX: 50,
        originY: 50,
        x: 0,
        y: 0,
      };
      this.viewerDrag.active = false;
    },
    clamp(value, min, max) {
      return Math.min(max, Math.max(min, value));
    },
    onKeydown(event) {
      if (!this.viewerOpen) return;
      if (event.key === "Escape") this.closeViewer();
      if (event.key === "ArrowLeft") this.moveViewer(-1);
      if (event.key === "ArrowRight") this.moveViewer(1);
      if (event.key === "0") this.resetViewerZoom();
    },
    preventImageMenu(event) {
      if (event.target.closest("img, .collection-card, .viewer")) event.preventDefault();
    },
    preventImageDrag(event) {
      if (event.target.closest("img")) event.preventDefault();
    },
    preventImageCopy(event) {
      const selection = String(window.getSelection?.() || "");
      if (!selection && event.target?.closest?.("img, .viewer")) event.preventDefault();
    },
  },
}).mount("#gallery-app");
