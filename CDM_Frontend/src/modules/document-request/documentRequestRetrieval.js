export function groupRetrievalTasks(tasks) {
  const cabinets = new Map()
  for (const task of tasks) {
    const currentSlot = task.student?.physical_record_location?.cabinet_slot
    const cabinet = task.status === 'retrieved' ? task.cabinet_code_snapshot : currentSlot?.cabinet?.cabinet_code
    const drawer = task.status === 'retrieved' ? task.slot_code_snapshot : currentSlot?.slot_code
    const cabinetKey = cabinet || 'Unassigned location'
    const drawerKey = drawer || 'No drawer assigned'
    if (!cabinets.has(cabinetKey)) cabinets.set(cabinetKey, new Map())
    const drawers = cabinets.get(cabinetKey)
    if (!drawers.has(drawerKey)) drawers.set(drawerKey, [])
    drawers.get(drawerKey).push(task)
  }
  return [...cabinets].map(([name, drawers]) => ({ name,
    drawers: [...drawers].map(([drawer, items]) => ({ name: drawer, items })) }))
}
