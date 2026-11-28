export default defineEventHandler((event) => {
  setHeader(event, "content-type", "text/event-stream");
  let tail: ReturnType<typeof setTimeout>;
  return sendStream(event, new ReadableStream({
    start(controller) {
      const encode = (value: string) => new TextEncoder().encode("data: " + value + "\n\n");
      controller.enqueue(encode(getCookie(event, "probe") ?? "no cookie"));
      tail = setTimeout(() => {
        controller.enqueue(encode("done"));
        controller.close();
      }, 6_000);
    },
    cancel() {
      clearTimeout(tail);
    },
  }));
});
