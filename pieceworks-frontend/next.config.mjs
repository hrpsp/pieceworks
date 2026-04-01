/** @type {import('next').NextConfig} */
const nextConfig = {
  output: 'standalone',
  eslint: {
    ignoreDuringBuilds: true,
  },
  typescript: {
    ignoreBuildErrors: true,
  },
  async rewrites() {
    return process.env.NODE_ENV === 'development'
      ? [
          {
            source:      '/api/:path*',
            destination: 'http://localhost:8000/api/:path*',
          },
        ]
      : [];
  },
  images: {
    unoptimized: true,
    remotePatterns: [],
  },
};

export default nextConfig;