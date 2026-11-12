export function clearJsonContentTypeForFormData(config) {
  if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
    config.headers?.delete?.('Content-Type')
  }

  return config
}
