window.KNXMT_Groups = (() => {
  async function addEmptyGroupToCurrentItem(ctx) {
    const { getCurrentItem, U, persistItem, hydrateUiFromState } = ctx;
    const item = getCurrentItem();
    if (!item) return;

    item.groups = U.normalizeGroups(item.groups || []);
    item.groups.push(U.createEmptyGroup());
    item.updated_at = Date.now();

    await persistItem(item);
    await hydrateUiFromState();
  }

  function updateGroupName(ctx, groupIndex, value) {
    const { getCurrentItem, queueSaveItem } = ctx;
    const item = getCurrentItem();
    if (!item) return;

    item.groups[groupIndex].name = value;
    item.updated_at = Date.now();
    queueSaveItem();
  }

  async function duplicateGroup(ctx, groupIndex) {
    const { getCurrentItem, U, persistItem, hydrateUiFromState } = ctx;
    const item = getCurrentItem();
    if (!item) return;

    const copy = U.deepClone(item.groups[groupIndex]);
    copy.name = copy.name ? `${copy.name} Copy` : 'Group Copy';
    item.groups.splice(groupIndex + 1, 0, U.normalizeGroup(copy));
    item.updated_at = Date.now();

    await persistItem(item);
    await hydrateUiFromState();
  }

  async function deleteGroup(ctx, groupIndex) {
    const { getCurrentItem, persistItem, hydrateUiFromState } = ctx;
    const item = getCurrentItem();
    if (!item) return;

    item.groups.splice(groupIndex, 1);
    item.updated_at = Date.now();

    await persistItem(item);
    await hydrateUiFromState();
  }

  function updateOptionName(ctx, groupIndex, optionIndex, value) {
    const { getCurrentItem, queueSaveItem } = ctx;
    const item = getCurrentItem();
    if (!item) return;

    item.groups[groupIndex].options[optionIndex].name = value;
    item.updated_at = Date.now();
    queueSaveItem();
  }

  function updateOptionPrice(ctx, groupIndex, optionIndex, value) {
    const { getCurrentItem, queueSaveItem } = ctx;
    const item = getCurrentItem();
    if (!item) return;

    item.groups[groupIndex].options[optionIndex].price_adjustment = value;
    item.updated_at = Date.now();
    queueSaveItem();
  }

  async function addOptionToGroup(ctx, groupIndex) {
    const { getCurrentItem, U, persistItem, hydrateUiFromState } = ctx;
    const item = getCurrentItem();
    if (!item) return;

    item.groups = U.normalizeGroups(item.groups || []);
    if (!item.groups[groupIndex]) return;

    item.groups[groupIndex].options.push(U.createEmptyOption());
    item.updated_at = Date.now();

    await persistItem(item);
    await hydrateUiFromState();
  }

  async function deleteOptionFromGroup(ctx, groupIndex, optionIndex) {
    const { getCurrentItem, U, persistItem, hydrateUiFromState } = ctx;
    const item = getCurrentItem();
    if (!item) return;

    item.groups = U.normalizeGroups(item.groups || []);
    if (!item.groups[groupIndex]) return;

    item.groups[groupIndex].options.splice(optionIndex, 1);
    item.updated_at = Date.now();

    await persistItem(item);
    await hydrateUiFromState();
  }

  return {
    addEmptyGroupToCurrentItem,
    updateGroupName,
    duplicateGroup,
    deleteGroup,
    updateOptionName,
    updateOptionPrice,
    addOptionToGroup,
    deleteOptionFromGroup,
  };
})();