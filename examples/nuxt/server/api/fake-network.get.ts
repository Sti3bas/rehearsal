export default defineEventHandler(async () => {
  const url = new URL("/state", useRuntimeConfig().rehearsal.laravelOrigin);
  try {
    return { status: (await fetch(url)).status };
  } catch (error) {
    if (!(error instanceof TypeError)) throw error;
    return { error: error.name };
  }
});
